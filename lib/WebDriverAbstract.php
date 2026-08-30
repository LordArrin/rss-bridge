<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Configuration;

/**
 * An alternative abstract class for bridges depending on a WebDriver.
 *
 * This class is meant for active websites that use XMLHttpRequest (XHR)
 * to load content and/or use JavaScript to change content.
 * This class depends on a working WebDriver setup (e.g. Selenium).
 */
abstract class WebDriverAbstract extends BridgeAbstract
{
    /**
     * Holds the remote WebDriver object, including configuration and connection.
     *
     * @var \Facebook\WebDriver\RemoteWebDriver|null
     */
    protected mixed $driver = null;

    /**
     * Holds the URI of the feed's icon.
     */
    private ?string $feedIcon = null;

    /**
     * Returns the WebDriver object.
     *
     * @return \Facebook\WebDriver\RemoteWebDriver|null
     */
    protected function getDriver(): mixed
    {
        return $this->driver;
    }

    /**
     * Returns the URI of the feed's icon.
     * Falls back to {@see BridgeAbstract::getIcon()} if no custom icon was set.
     */
    public function getIcon(): string
    {
        return $this->feedIcon ?: parent::getIcon();
    }

    /**
     * Sets the URI of the feed's icon.
     *
     * @param string $iconurl Icon URL
     */
    protected function setIcon(string $iconurl): void
    {
        $this->feedIcon = $iconurl;
    }

    /**
     * Returns the ChromeOptions object.
     *
     * If the configuration parameter 'headless' is set to true,
     * the argument '--headless' is added. Override this to change
     * or add more options.
     *
     * @return \Facebook\WebDriver\ChromeOptions
     */
    protected function getBrowserOptions(): mixed
    {
        $chromeOptions = new \Facebook\WebDriver\ChromeOptions();
        if (Configuration::getConfig('webdriver', 'headless')) {
            $chromeOptions->addArguments(['--headless']);
        }
        return $chromeOptions;
    }

    /**
     * Returns the DesiredCapabilities object for the Chrome browser.
     *
     * The Chrome options are added. Override this to change
     * or add more capabilities.
     *
     * @return \Facebook\WebDriver\WebDriverCapabilities
     */
    protected function getDesiredCapabilities(): mixed
    {
        $desiredCapabilities = \Facebook\WebDriver\Remote\DesiredCapabilities::chrome();
        $desiredCapabilities->setCapability(
            \Facebook\WebDriver\ChromeOptions::CAPABILITY,
            $this->getBrowserOptions()
        );
        return $desiredCapabilities;
    }

    /**
     * Constructs the remote WebDriver with the URL of the remote (Selenium)
     * WebDriver server and the desired capabilities.
     *
     * This should be called in collectData() first.
     */
    protected function prepareWebDriver(): void
    {
        $server = Configuration::getConfig('webdriver', 'selenium_server_url');
        $this->driver = \Facebook\WebDriver\Remote\RemoteWebDriver::create(
            $server,
            $this->getDesiredCapabilities()
        );
    }

    /**
     * Maximizes the remote browser window (often important for reactive sites
     * which change their appearance depending on the window size) and opens
     * the URI set in the constant URI.
     */
    protected function prepareWindow(): void
    {
        $this->getDriver()->manage()->window()->maximize();
        $this->getDriver()->get($this->getURI());
    }

    /**
     * Closes the remote browser window and shuts down the remote WebDriver
     * connection.
     *
     * This must be called at the end of scraping, for example within a
     * 'finally' block.
     */
    protected function cleanUp(): void
    {
        $this->getDriver()->quit();
    }

    /**
     * Do your web scraping here and fill the $items array.
     *
     * Override this but call parent() first.
     * Don't forget to call cleanUp() at the end.
     */
    public function collectData(): void
    {
        $this->prepareWebDriver();
        $this->prepareWindow();
    }
}
