<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\HttpKernel\DependencyInjection\ResettableServicePass;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Tests\Fixtures\KernelForTest;
use Symfony\Component\HttpKernel\Tests\Fixtures\KernelWithoutBundles;
use Symfony\Component\HttpKernel\Tests\Fixtures\ResettableService;

class KernelTest extends TestCase
{
    use ExpectDeprecationTrait;

    protected function tearDown(): void
    {
        try {
            (new Filesystem())->remove(__DIR__.'/Fixtures/var');
        } catch (IOException $e) {
        }
    }

    public function testConstructor()
    {
        $env = 'test_env';
        $debug = true;
        $kernel = new KernelForTest($env, $debug);

        $this->assertEquals($env, $kernel->getEnvironment());
        $this->assertEquals($debug, $kernel->isDebug());
        $this->assertFalse($kernel->isBooted());
        $this->assertLessThanOrEqual(microtime(true), $kernel->getStartTime());
    }

    public function testEmptyEnv()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Invalid environment provided to "%s": the environment cannot be empty.', KernelForTest::class));

        new KernelForTest('', false);
    }

    public function testClone()
    {
        $env = 'test_env';
        $debug = true;
        $kernel = new KernelForTest($env, $debug);

        $clone = clone $kernel;

        $this->assertEquals($env, $clone->getEnvironment());
        $this->assertEquals($debug, $clone->isDebug());
        $this->assertFalse($clone->isBooted());
        $this->assertLessThanOrEqual(microtime(true), $clone->getStartTime());
    }

    public function testClassNameValidityGetter()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The environment "test.env" contains invalid characters, it can only contain characters allowed in PHP class names.');
        // We check the classname that will be generated by using a $env that
        // contains invalid characters.
        $env = 'test.env';
        $kernel = new KernelForTest($env, false, false);

        $kernel->boot();
    }

    public function testInitializeContainerClearsOldContainers()
    {
        $fs = new Filesystem();
        $legacyContainerDir = __DIR__.'/Fixtures/var/cache/custom/ContainerA123456';
        $fs->mkdir($legacyContainerDir);
        touch($legacyContainerDir.'.legacy');

        $kernel = new CustomProjectDirKernel();
        $kernel->boot();

        $containerDir = __DIR__.'/Fixtures/var/cache/custom/'.substr($kernel->getContainer()::class, 0, 16);
        $this->assertTrue(unlink(__DIR__.'/Fixtures/var/cache/custom/Symfony_Component_HttpKernel_Tests_CustomProjectDirKernelCustomDebugContainer.php.meta'));
        $this->assertFileExists($containerDir);
        $this->assertFileDoesNotExist($containerDir.'.legacy');

        $kernel = new CustomProjectDirKernel(function ($container) { $container->register('foo', 'stdClass')->setPublic(true); });
        $kernel->boot();

        $this->assertFileExists($containerDir);
        $this->assertFileExists($containerDir.'.legacy');

        $this->assertFileDoesNotExist($legacyContainerDir);
        $this->assertFileDoesNotExist($legacyContainerDir.'.legacy');
    }

    public function testBootInitializesBundlesAndContainer()
    {
        $kernel = $this->getKernel(['initializeBundles']);
        $kernel->expects($this->once())
            ->method('initializeBundles');

        $kernel->boot();
    }

    public function testBootSetsTheContainerToTheBundles()
    {
        $bundle = $this->createMock(Bundle::class);
        $bundle->expects($this->once())
            ->method('setContainer');

        $kernel = $this->getKernel(['initializeBundles', 'getBundles']);
        $kernel->expects($this->once())
            ->method('getBundles')
            ->willReturn([$bundle]);

        $kernel->boot();
    }

    public function testBootSetsTheBootedFlagToTrue()
    {
        // use test kernel to access isBooted()
        $kernel = $this->getKernel(['initializeBundles']);
        $kernel->boot();

        $this->assertTrue($kernel->isBooted());
    }

    public function testClassCacheIsNotLoadedByDefault()
    {
        $kernel = $this->getKernel(['initializeBundles', 'doLoadClassCache']);
        $kernel->expects($this->never())
            ->method('doLoadClassCache');

        $kernel->boot();
    }

    public function testBootKernelSeveralTimesOnlyInitializesBundlesOnce()
    {
        $kernel = $this->getKernel(['initializeBundles']);
        $kernel->expects($this->once())
            ->method('initializeBundles');

        $kernel->boot();
        $kernel->boot();
    }

    public function testShutdownCallsShutdownOnAllBundles()
    {
        $bundle = $this->createMock(Bundle::class);
        $bundle->expects($this->once())
            ->method('shutdown');

        $kernel = $this->getKernel([], [$bundle]);

        $kernel->boot();
        $kernel->shutdown();
    }

    public function testShutdownGivesNullContainerToAllBundles()
    {
        $bundle = $this->createMock(Bundle::class);
        $bundle->expects($this->exactly(2))
            ->method('setContainer')
            ->withConsecutive(
                [$this->isInstanceOf(ContainerInterface::class)],
                [null]
            );

        $kernel = $this->getKernel(['getBundles']);
        $kernel->expects($this->any())
            ->method('getBundles')
            ->willReturn([$bundle]);

        $kernel->boot();
        $kernel->shutdown();
    }

    public function testHandleCallsHandleOnHttpKernel()
    {
        $type = HttpKernelInterface::MAIN_REQUEST;
        $catch = true;
        $request = new Request();

        $httpKernelMock = $this->getMockBuilder(HttpKernel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $httpKernelMock
            ->expects($this->once())
            ->method('handle')
            ->with($request, $type, $catch);

        $kernel = $this->getKernel(['getHttpKernel']);
        $kernel->expects($this->once())
            ->method('getHttpKernel')
            ->willReturn($httpKernelMock);

        $kernel->handle($request, $type, $catch);
    }

    public function testHandleBootsTheKernel()
    {
        $type = HttpKernelInterface::MAIN_REQUEST;
        $catch = true;
        $request = new Request();

        $httpKernelMock = $this->getMockBuilder(HttpKernel::class)
            ->disableOriginalConstructor()
            ->getMock();

        $kernel = $this->getKernel(['getHttpKernel', 'boot']);
        $kernel->expects($this->once())
            ->method('getHttpKernel')
            ->willReturn($httpKernelMock);

        $kernel->expects($this->once())
            ->method('boot');

        $kernel->handle($request, $type, $catch);
    }

    /**
     * @dataProvider getStripCommentsCodes
     */
    public function testStripComments(string $source, string $expected)
    {
        $output = Kernel::stripComments($source);

        // Heredocs are preserved, making the output mixing Unix and Windows line
        // endings, switching to "\n" everywhere on Windows to avoid failure.
        if ('\\' === \DIRECTORY_SEPARATOR) {
            $expected = str_replace("\r\n", "\n", $expected);
            $output = str_replace("\r\n", "\n", $output);
        }

        $this->assertEquals($expected, $output);
    }

    public function getStripCommentsCodes(): array
    {
        return [
            ['<?php echo foo();', '<?php echo foo();'],
            ['<?php echo/**/foo();', '<?php echo foo();'],
            ['<?php echo/** bar */foo();', '<?php echo foo();'],
            ['<?php /**/echo foo();', '<?php echo foo();'],
            ['<?php echo \foo();', '<?php echo \foo();'],
            ['<?php echo/**/\foo();', '<?php echo \foo();'],
            ['<?php echo/** bar */\foo();', '<?php echo \foo();'],
            ['<?php /**/echo \foo();', '<?php echo \foo();'],
            [<<<'EOF'
<?php
include_once \dirname(__DIR__).'/foo.php';

$string = 'string should not be   modified';

$string = 'string should not be

modified';


$heredoc = <<<HD


Heredoc should not be   modified {$a[1+$b]}


HD;

$nowdoc = <<<'ND'


Nowdoc should not be   modified


ND;

/**
 * some class comments to strip
 */
class TestClass
{
    /**
     * some method comments to strip
     */
    public function doStuff()
    {
        // inline comment
    }
}
EOF
, <<<'EOF'
<?php
include_once \dirname(__DIR__).'/foo.php';
$string = 'string should not be   modified';
$string = 'string should not be

modified';
$heredoc = <<<HD


Heredoc should not be   modified {$a[1+$b]}


HD;
$nowdoc = <<<'ND'


Nowdoc should not be   modified


ND;
class TestClass
{
    public function doStuff()
    {
        }
}
EOF
            ],
        ];
    }

    public function testSerialize()
    {
        $env = 'test_env';
        $debug = true;
        $kernel = new KernelForTest($env, $debug);
        $expected = "O:57:\"Symfony\Component\HttpKernel\Tests\Fixtures\KernelForTest\":2:{s:14:\"\0*\0environment\";s:8:\"test_env\";s:8:\"\0*\0debug\";b:1;}";
        $this->assertEquals($expected, serialize($kernel));
    }

    public function testLocateResourceThrowsExceptionWhenNameIsNotValid()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getKernel()->locateResource('Foo');
    }

    public function testLocateResourceThrowsExceptionWhenNameIsUnsafe()
    {
        $this->expectException(\RuntimeException::class);
        $this->getKernel()->locateResource('@FooBundle/../bar');
    }

    public function testLocateResourceThrowsExceptionWhenBundleDoesNotExist()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getKernel()->locateResource('@FooBundle/config/routing.xml');
    }

    public function testLocateResourceThrowsExceptionWhenResourceDoesNotExist()
    {
        $this->expectException(\InvalidArgumentException::class);
        $kernel = $this->getKernel(['getBundle']);
        $kernel
            ->expects($this->once())
            ->method('getBundle')
            ->willReturn($this->getBundle(__DIR__.'/Fixtures/Bundle1Bundle'))
        ;

        $kernel->locateResource('@Bundle1Bundle/config/routing.xml');
    }

    public function testLocateResourceReturnsTheFirstThatMatches()
    {
        $kernel = $this->getKernel(['getBundle']);
        $kernel
            ->expects($this->once())
            ->method('getBundle')
            ->willReturn($this->getBundle(__DIR__.'/Fixtures/Bundle1Bundle'))
        ;

        $this->assertEquals(__DIR__.'/Fixtures/Bundle1Bundle/foo.txt', $kernel->locateResource('@Bundle1Bundle/foo.txt'));
    }

    public function testLocateResourceOnDirectories()
    {
        $kernel = $this->getKernel(['getBundle']);
        $kernel
            ->expects($this->exactly(2))
            ->method('getBundle')
            ->willReturn($this->getBundle(__DIR__.'/Fixtures/Bundle1Bundle', null, null, 'Bundle1Bundle'))
        ;

        $this->assertEquals(
            __DIR__.'/Fixtures/Bundle1Bundle/Resources/',
            $kernel->locateResource('@Bundle1Bundle/Resources/')
        );
        $this->assertEquals(
            __DIR__.'/Fixtures/Bundle1Bundle/Resources',
            $kernel->locateResource('@Bundle1Bundle/Resources')
        );
    }

    public function testInitializeBundleThrowsExceptionWhenRegisteringTwoBundlesWithTheSameName()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Trying to register two bundles with the same name "DuplicateName"');
        $fooBundle = $this->getBundle(__DIR__.'/Fixtures/FooBundle', null, 'FooBundle', 'DuplicateName');
        $barBundle = $this->getBundle(__DIR__.'/Fixtures/BarBundle', null, 'BarBundle', 'DuplicateName');

        $kernel = $this->getKernel([], [$fooBundle, $barBundle]);
        $kernel->boot();
    }

    public function testTerminateReturnsSilentlyIfKernelIsNotBooted()
    {
        $kernel = $this->getKernel(['getHttpKernel']);
        $kernel->expects($this->never())
            ->method('getHttpKernel');

        $kernel->terminate(Request::create('/'), new Response());
    }

    public function testTerminateDelegatesTerminationOnlyForTerminableInterface()
    {
        // does not implement TerminableInterface
        $httpKernel = new TestKernel();

        $kernel = $this->getKernel(['getHttpKernel']);
        $kernel->expects($this->once())
            ->method('getHttpKernel')
            ->willReturn($httpKernel);

        $kernel->boot();
        $kernel->terminate(Request::create('/'), new Response());

        $this->assertFalse($httpKernel->terminateCalled, 'terminate() is never called if the kernel class does not implement TerminableInterface');

        // implements TerminableInterface
        $httpKernelMock = $this->getMockBuilder(HttpKernel::class)
            ->disableOriginalConstructor()
            ->setMethods(['terminate'])
            ->getMock();

        $httpKernelMock
            ->expects($this->once())
            ->method('terminate');

        $kernel = $this->getKernel(['getHttpKernel']);
        $kernel->expects($this->exactly(2))
            ->method('getHttpKernel')
            ->willReturn($httpKernelMock);

        $kernel->boot();
        $kernel->terminate(Request::create('/'), new Response());
    }

    public function testKernelWithoutBundles()
    {
        $kernel = new KernelWithoutBundles('test', true);
        $kernel->boot();

        $this->assertTrue($kernel->getContainer()->getParameter('test_executed'));
    }

    public function testProjectDirExtension()
    {
        $kernel = new CustomProjectDirKernel();
        $kernel->boot();

        $this->assertSame(__DIR__.'/Fixtures', $kernel->getProjectDir());
        $this->assertSame(__DIR__.\DIRECTORY_SEPARATOR.'Fixtures', $kernel->getContainer()->getParameter('kernel.project_dir'));
    }

    public function testKernelReset()
    {
        $this->tearDown();

        $kernel = new CustomProjectDirKernel();
        $kernel->boot();

        $containerClass = $kernel->getContainer()::class;
        $containerFile = (new \ReflectionClass($kernel->getContainer()))->getFileName();
        unlink(__DIR__.'/Fixtures/var/cache/custom/Symfony_Component_HttpKernel_Tests_CustomProjectDirKernelCustomDebugContainer.php.meta');

        $kernel = new CustomProjectDirKernel();
        $kernel->boot();

        $this->assertInstanceOf($containerClass, $kernel->getContainer());
        $this->assertFileExists($containerFile);
        unlink(__DIR__.'/Fixtures/var/cache/custom/Symfony_Component_HttpKernel_Tests_CustomProjectDirKernelCustomDebugContainer.php.meta');

        $kernel = new CustomProjectDirKernel(function ($container) { $container->register('foo', 'stdClass')->setPublic(true); });
        $kernel->boot();

        $this->assertNotInstanceOf($containerClass, $kernel->getContainer());
        $this->assertFileExists($containerFile);
        $this->assertFileExists(\dirname($containerFile).'.legacy');
    }

    public function testKernelExtension()
    {
        $kernel = new class() extends CustomProjectDirKernel implements ExtensionInterface {
            public function load(array $configs, ContainerBuilder $container)
            {
                $container->setParameter('test.extension-registered', true);
            }

            public function getNamespace(): string
            {
                return '';
            }

            public function getXsdValidationBasePath(): string|false
            {
                return false;
            }

            public function getAlias(): string
            {
                return 'test-extension';
            }
        };
        $kernel->boot();

        $this->assertTrue($kernel->getContainer()->getParameter('test.extension-registered'));
    }

    public function testKernelPass()
    {
        $kernel = new PassKernel();
        $kernel->boot();

        $this->assertTrue($kernel->getContainer()->getParameter('test.processed'));
    }

    public function testWarmup()
    {
        $kernel = new CustomProjectDirKernel();
        $kernel->boot();

        $this->assertTrue($kernel->warmedUp);
    }

    public function testServicesResetter()
    {
        $httpKernelMock = $this->getMockBuilder(HttpKernelInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $httpKernelMock
            ->expects($this->exactly(2))
            ->method('handle');

        $kernel = new CustomProjectDirKernel(function ($container) {
            $container->addCompilerPass(new ResettableServicePass());
            $container->register('one', ResettableService::class)
                ->setPublic(true)
                ->addTag('kernel.reset', ['method' => 'reset']);
            $container->register('services_resetter', ServicesResetter::class)->setPublic(true);
        }, $httpKernelMock, 'resetting');

        ResettableService::$counter = 0;

        $request = new Request();

        $kernel->handle($request);
        $kernel->getContainer()->get('one');

        $this->assertEquals(0, ResettableService::$counter);
        $this->assertFalse($kernel->getContainer()->initialized('services_resetter'));

        $kernel->handle($request);

        $this->assertEquals(1, ResettableService::$counter);
    }

    /**
     * @group time-sensitive
     */
    public function testKernelStartTimeIsResetWhileBootingAlreadyBootedKernel()
    {
        $kernel = $this->getKernel(['initializeBundles'], [], true);
        $kernel->boot();
        $preReBoot = $kernel->getStartTime();

        sleep(3600); // Intentionally large value to detect if ClockMock ever breaks
        $kernel->reboot(null);

        $this->assertGreaterThan($preReBoot, $kernel->getStartTime());
    }

    public function testAnonymousKernelGeneratesValidContainerClass()
    {
        $kernel = new class('test', true) extends Kernel {
            public function registerBundles(): iterable
            {
                return [];
            }

            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
            }

            public function getContainerClass(): string
            {
                return parent::getContainerClass();
            }
        };

        $this->assertMatchesRegularExpression('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*TestDebugContainer$/', $kernel->getContainerClass());
    }

    /**
     * @group legacy
     */
    public function testKernelWithParameterDeprecation()
    {
        $kernel = new class('test', true) extends Kernel {
            public function __construct(string $env, bool $debug)
            {
                $this->container = new ContainerBuilder(new ParameterBag(['container.dumper.inline_factories' => true, 'container.dumper.inline_class_loader' => true]));
                parent::__construct($env, $debug);
            }

            public function registerBundles(): iterable
            {
                return [];
            }

            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
            }

            public function boot()
            {
                $this->container->compile();
                parent::dumpContainer(new ConfigCache(tempnam('/tmp', 'symfony-kernel-deprecated-parameter'), true), $this->container, Container::class, $this->getContainerBaseClass());
            }

            public function getContainerClass(): string
            {
                return parent::getContainerClass();
            }
        };

        $this->expectDeprecation('Since symfony/http-kernel 6.3: "container.dumper.inline_factories" is deprecated, use ".container.dumper.inline_factories" instead.');
        $this->expectDeprecation('Since symfony/http-kernel 6.3: "container.dumper.inline_class_loader" is deprecated, use ".container.dumper.inline_class_loader" instead.');

        $kernel->boot();
    }

    /**
     * Returns a mock for the BundleInterface.
     */
    protected function getBundle($dir = null, $parent = null, $className = null, $bundleName = null): BundleInterface
    {
        $bundle = $this
            ->getMockBuilder(BundleInterface::class)
            ->setMethods(['getPath', 'getName'])
            ->disableOriginalConstructor()
        ;

        if ($className) {
            $bundle->setMockClassName($className);
        }

        $bundle = $bundle->getMockForAbstractClass();

        $bundle
            ->expects($this->any())
            ->method('getName')
            ->willReturn($bundleName ?? $bundle::class)
        ;

        $bundle
            ->expects($this->any())
            ->method('getPath')
            ->willReturn($dir)
        ;

        return $bundle;
    }

    /**
     * Returns a mock for the abstract kernel.
     *
     * @param array $methods Additional methods to mock (besides the abstract ones)
     * @param array $bundles Bundles to register
     */
    protected function getKernel(array $methods = [], array $bundles = [], bool $debug = false): Kernel
    {
        $methods[] = 'registerBundles';

        $kernel = $this
            ->getMockBuilder(KernelForTest::class)
            ->setMethods($methods)
            ->setConstructorArgs(['test', $debug])
            ->getMock()
        ;
        $kernel->expects($this->any())
            ->method('registerBundles')
            ->willReturn($bundles)
        ;

        return $kernel;
    }
}

class TestKernel implements HttpKernelInterface
{
    public $terminateCalled = false;

    public function terminate()
    {
        $this->terminateCalled = true;
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
    }

    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures';
    }
}

class CustomProjectDirKernel extends Kernel implements WarmableInterface
{
    public $warmedUp = false;
    private $baseDir;
    private $buildContainer;
    private $httpKernel;

    public function __construct(\Closure $buildContainer = null, HttpKernelInterface $httpKernel = null, $env = 'custom')
    {
        parent::__construct($env, true);

        $this->buildContainer = $buildContainer;
        $this->httpKernel = $httpKernel;
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
    }

    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures';
    }

    public function warmUp(string $cacheDir): array
    {
        $this->warmedUp = true;

        return [];
    }

    protected function build(ContainerBuilder $container)
    {
        if ($build = $this->buildContainer) {
            $build($container);
        }
    }

    protected function getHttpKernel(): HttpKernelInterface
    {
        return $this->httpKernel;
    }
}

class PassKernel extends CustomProjectDirKernel implements CompilerPassInterface
{
    public function __construct()
    {
        parent::__construct();
        Kernel::__construct('pass', true);
    }

    public function process(ContainerBuilder $container)
    {
        $container->setParameter('test.processed', true);
    }
}
