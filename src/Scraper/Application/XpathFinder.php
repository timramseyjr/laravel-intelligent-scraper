<?php

namespace Softonic\LaravelIntelligentScraper\Scraper\Application;

use Goutte\Client as GoutteClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Log\Logger;
use Softonic\LaravelIntelligentScraper\Scraper\Exceptions\MissingXpathValueException;

class XpathFinder
{
    /**
     * @var GoutteClient
     */
    private $client;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var VariantGenerator
     */
    private $variantGenerator;

    public function __construct(GoutteClient $client, VariantGenerator $variantGenerator,  Logger $logger)
    {
        $this->client           = $client;
        $this->variantGenerator = $variantGenerator;
        $this->logger        = $logger;
    }

    public function extract(string $url, $configs): array
    {
        $crawler = $this->getCrawler($url);

        $this->logger->info('Response Received. Start crawling.');
        $result = [];
        foreach ($configs as $config) {
            $this->logger->info("Searching field {$config['name']}.");
            $subcrawler = collect();
            $xpaths = is_array($config['xpaths']) ? $config['xpaths'] : [$config['xpaths']];
            foreach ($xpaths as $xpath) {
                $this->logger->debug("Checking xpath {$xpath}");
                $subcrawler = $crawler->filterXPath($xpath);

                if ($subcrawler->count()) {
                    $this->logger->debug("Found xpath {$xpath}");
                    $this->variantGenerator->addConfig($config['name'], $xpath);
                    break;
                }
            }

            if (!$subcrawler->count()) {
                $missingXpath = is_array($config['xpaths']) ? implode('\', \'', $config['xpaths']) : $config['xpaths'];
                $this->logger->debug("Xpath '{$missingXpath}' for field '{$config['name']}' not found in '{$url}'.");
                /*throw new MissingXpathValueException(
                    "Xpath '{$missingXpath}' for field '{$config['name']}' not found in '{$url}'."
                );*/
            }

            $result['data'][$config['name']] = $subcrawler->each(function ($node) {
                return $node->text();
            });
        }

        $this->logger->info('Calculating variant.');
        $result['variant'] = $this->variantGenerator->getId($config['type']);
        $this->logger->info('Variant calculated.');

        return $result;
    }

    private function getCrawler(string $url)
    {
        try {
            $this->logger->info("Requesting $url");

            return $this->client->request('GET', $url);
        } catch (ConnectException $e) {
            $this->logger->info("Unavailable url '{$url}'", ['message' => $e->getMessage()]);
            $proxy = explode(':',$this->client->getClient()->getConfig()['proxy']['https']);
            $db_proxy = \TimRamseyJr\Scraper\Models\Proxies::where('ip',$proxy[0])->where('port',$proxy[1])->first();
            $db_proxy->rejected_message = $e->getMessage();
            $db_proxy->rejected_at = \Carbon\Carbon::now();
            $db_proxy->save();
            throw new \UnexpectedValueException("Unavailable url '{$url}'");
        } catch (RequestException $e) {
            $httpCode = $e->getResponse()->getStatusCode();
            $this->logger->info('Invalid response http status', ['status' => $httpCode]);
            $proxy = explode(':',$this->client->getClient()->getConfig()['proxy']['https']);
            $db_proxy = \TimRamseyJr\Scraper\Models\Proxies::where('ip',$proxy[0])->where('port',$proxy[1])->first();
            $db_proxy->rejected_message = $e->getMessage();
            $db_proxy->rejected_at = \Carbon\Carbon::now();
            $db_proxy->save();
            throw new \UnexpectedValueException("Response error from '{$url}' with '{$httpCode}' http code");
        }
    }
}
