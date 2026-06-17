<?php

namespace App\Services\OtakuShop\Crawler;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Facades\Log;

/**
 * Selenium WebDriver 래퍼 (Laravel 12 / php-webdriver 호환).
 */
class CrawlerDriver
{
    private ?RemoteWebDriver $driver = null;

    public function __construct(
        private string $driverUrl,
        private bool $headless = true,
        private int $pageLoadTimeout = 30,
        private int $implicitWait = 10,
        private int $connectionTimeout = 30,
        private int $requestTimeout = 60,
    ) {}

    /**
     * config에서 인스턴스 생성.
     */
    public static function fromConfig(): self
    {
        $config = config('otaku-crawler.selenium', []);

        return new self(
            $config['driver_url'] ?? 'http://localhost:9515',
            (bool) ($config['headless'] ?? true),
            (int) ($config['page_load_timeout_sec'] ?? 30),
            (int) ($config['implicit_wait_sec'] ?? 10),
            (int) ($config['connection_timeout_sec'] ?? 30),
            (int) ($config['request_timeout_sec'] ?? 60),
        );
    }

    /**
     * WebDriver 세션 시작 (Chrome).
     */
    public function start(): RemoteWebDriver
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $caps = DesiredCapabilities::chrome();
        $options = new ChromeOptions();
        if ($this->headless) {
            $options->addArguments(['--headless=new', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage']);
        }
        $options->addArguments(['--window-size=1920,1080']);
        $caps->setCapability(ChromeOptions::CAPABILITY, $options);

        // 3·4번째 인자(연결/요청 타임아웃, ms)를 반드시 준다. 안 주면 curl 타임아웃이 무한이라
        // 브라우저가 응답을 안 주는 페이지에서 명령 하나가 영원히 블록돼 크롤 전체가 멈춘다.
        $this->driver = RemoteWebDriver::create(
            $this->driverUrl,
            $caps,
            $this->connectionTimeout * 1000,
            $this->requestTimeout * 1000,
        );
        $this->driver->manage()->timeouts()->pageLoadTimeout($this->pageLoadTimeout);
        $this->driver->manage()->timeouts()->implicitlyWait($this->implicitWait);

        Log::info('OtakuShop Crawler: WebDriver started', ['url' => $this->driverUrl]);

        return $this->driver;
    }

    /**
     * 현재 WebDriver 인스턴스 (시작되지 않았으면 시작).
     */
    public function getDriver(): RemoteWebDriver
    {
        return $this->driver ?? $this->start();
    }

    /**
     * 세션 종료.
     */
    public function quit(): void
    {
        if ($this->driver !== null) {
            try {
                $this->driver->quit();
            } catch (\Throwable $e) {
                Log::warning('OtakuShop Crawler: WebDriver quit error', ['message' => $e->getMessage()]);
            }
            $this->driver = null;
        }
    }

    public function __destruct()
    {
        $this->quit();
    }
}
