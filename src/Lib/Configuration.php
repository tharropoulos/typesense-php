<?php

namespace Typesense\Lib;

use Http\Client\Common\HttpMethodsClient;
use Http\Client\HttpClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Typesense\Exceptions\ConfigError;

/**
 * Class Configuration
 *
 * @package \Typesense
 * @date    4/5/20
 * @author  Abdullah Al-Faqeir <abdullah@devloops.net>
 */
class Configuration
{
    /**
     * @var Node[]
     */
    private array $nodes;

    /**
     * @var Node|null
     */
    private ?Node $nearestNode;

    /**
     * @var string
     */
    private string $apiKey;

    /**
     * @var float
     */
    private float $numRetries;

    /**
     * @var float
     */
    private float $retryIntervalSeconds;

    /**
     * @var int
     */
    private int $healthCheckIntervalSeconds;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var HttpMethodsClient|ClientInterface|null
     */
    private $client = null;

    /**
     * @var int
     */
    private int $logLevel;

    /**
     * @var bool
     */
    private bool $randomizeNodes;

    /**
     * Configuration constructor.
     *
     * @param array $config
     *
     * @throws ConfigError
     */
    public function __construct(array $config)
    {
        $this->validateConfigArray($config);

        $nodes = $config['nodes'] ?? [];

        foreach ($nodes as $node) {
            $this->nodes[] = new Node($node['host'], $node['port'], $node['path'] ?? '', $node['protocol']);
        }

        $this->randomizeNodes = $config['randomize_nodes'] ?? true;
        if ($this->randomizeNodes) {
            $this->shuffleNodes();
        }

        $nearestNode       = $config['nearest_node'] ?? null;
        $this->nearestNode = null;
        if (null !== $nearestNode) {
            $this->nearestNode =
                new Node(
                    $nearestNode['host'],
                    $nearestNode['port'],
                    $nearestNode['path'] ?? '',
                    $nearestNode['protocol']
                );
        }

        $this->apiKey = $config['api_key'] ?? '';
        $this->healthCheckIntervalSeconds = (int)($config['healthcheck_interval_seconds'] ?? 60);
        $this->numRetries           = (float)($config['num_retries'] ?? 3);
        $this->retryIntervalSeconds = (float)($config['retry_interval_seconds'] ?? 1.0);

        $this->logLevel = $config['log_level'] ?? Logger::WARNING;
        $this->logger   = new Logger('typesense');
        $this->logger->pushHandler(new StreamHandler('php://stdout', $this->logLevel));

        if (isset($config['client'])) {
            if ($config['client'] instanceof HttpMethodsClient || $config['client'] instanceof ClientInterface) {
                $this->client = $config['client'];
            } elseif ($config['client'] instanceof HttpClient) {
                $this->client = new HttpMethodsClient(
                    $config['client'],
                    Psr17FactoryDiscovery::findRequestFactory(),
                    Psr17FactoryDiscovery::findStreamFactory()
                );
            } else {
                throw new ConfigError('Client must implement PSR-18 ClientInterface or Http\Client\HttpClient');
            }
        }
    }

    /**
     * @param array $config
     *
     * @throws ConfigError
     */
    private function validateConfigArray(array $config): void
    {
        $nodes = $config['nodes'] ?? false;
        if (!$nodes) {
            throw new ConfigError('`nodes` is not defined.');
        }

        $apiKey = $config['api_key'] ?? false;
        if (!$apiKey) {
            throw new ConfigError('`api_key` is not defined.');
        }

        foreach ($nodes as $node) {
            if (!$this->validateNodeFields($node)) {
                throw new ConfigError(
                    '`node` entry be a dictionary with the following required keys: host, port, protocol, api_key'
                );
            }
        }
        $nearestNode = $config['nearest_node'] ?? [];
        if (!empty($nearestNode) && !$this->validateNodeFields($nearestNode)) {
            throw new ConfigError(
                '`nearest_node` entry be a dictionary with the following required keys: host, port, protocol, api_key'
            );
        }
    }

    /**
     * @param array $node
     *
     * @return bool
     */
    public function validateNodeFields(array $node): bool
    {
        $keys = [
            'host',
            'port',
            'protocol',
        ];
        return !array_diff_key(array_flip($keys), $node);
    }

    /**
     * @return Node[]
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return Node
     */
    public function getNearestNode(): ?Node
    {
        return $this->nearestNode;
    }

    /**
     * @return mixed|string
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * @return float
     */
    public function getNumRetries(): float
    {
        return $this->numRetries;
    }

    /**
     * @return float
     */
    public function getRetryIntervalSeconds(): float
    {
        return $this->retryIntervalSeconds;
    }

    /**
     * @return float|mixed
     */
    public function getHealthCheckIntervalSeconds()
    {
        return $this->healthCheckIntervalSeconds;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return ClientInterface | HttpMethodsClient
     */
    public function getClient(): ClientInterface | HttpMethodsClient
    {
        if ($this->client === null) {
            $discoveredClient = Psr18ClientDiscovery::find();
            $this->client = new HttpMethodsClient(
                $discoveredClient,
                Psr17FactoryDiscovery::findRequestFactory(),
                Psr17FactoryDiscovery::findStreamFactory()
            );
        }
        return $this->client;
    }

    /**
     * Shuffles the nodes array using Fisher-Yates algorithm
     */
    private function shuffleNodes(): void
    {
        $count = count($this->nodes);
        for ($i = $count - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $temp = $this->nodes[$i];
            $this->nodes[$i] = $this->nodes[$j];
            $this->nodes[$j] = $temp;
        }
    }
}
