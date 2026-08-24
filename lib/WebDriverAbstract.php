<?php

declare(strict_types=1);

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
     * @var RemoteWebDriver|null
     */
    protected $driver;

    /**
     * Holds the URI of the feed's icon.
     *
     * @var string|null
     */
    private $feedIcon;

    /**
     * Returns the WebDriver object.
     *
     * @return RemoteWebDriver|null
     */
    protected function getDriver()
    {
        return $this->driver;
    }

    /**
     * Returns the URI of the feed's icon.
     * Falls back to {@see BridgeAbstract::getIcon()} if no custom icon was set.
     */
    public function getIcon()
    {
        return $this->feedIcon ?: parent::getIcon();
    }

    /**
     * Sets the URI of the feed's icon.
     *
     * @param string $iconurl Icon URL
     */
    protected function setIcon($iconurl)
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
     * @return ChromeOptions
     */
    protected function getBrowserOptions()
    {
        $chromeOptions = new ChromeOptions();
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
     * @return WebDriverCapabilities
     */
    protected function getDesiredCapabilities()
    {
        $desiredCapabilities = DesiredCapabilities::chrome();
        $desiredCapabilities->setCapability(ChromeOptions::CAPABILITY, $this->getBrowserOptions());
        return $desiredCapabilities;
    }

    /**
     * Constructs the remote WebDriver with the URL of the remote (Selenium)
     * WebDriver server and the desired capabilities.
     *
     * This should be called in collectData() first.
     */
    protected function prepareWebDriver()
    {
        $server = Configuration::getConfig('webdriver', 'selenium_server_url');
        $this->driver = RemoteWebDriver::create($server, $this->getDesiredCapabilities());
    }

    /**
     * Maximizes the remote browser window (often important for reactive sites
     * which change their appearance depending on the window size) and opens
     * the URI set in the constant URI.
     */
    protected function prepareWindow()
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
    protected function cleanUp()
    {
        $this->getDriver()->quit();
    }

    /**
     * Do your web scraping here and fill the $items array.
     *
     * Override this but call parent() first.
     * Don't forget to call cleanUp() at the end.
     */
    public function collectData()
    {
        $this->prepareWebDriver();
        $this->prepareWindow();
    }
}
