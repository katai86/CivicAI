<?php
/**
 * Registry of virtual sensor providers. Returns only configured adapters.
 */
namespace CivicAI\Iot;

require_once __DIR__ . '/VirtualSensorProviderInterface.php';
require_once __DIR__ . '/OpenAQAdapter.php';
require_once __DIR__ . '/OpenWeatherAdapter.php';
require_once __DIR__ . '/AQICNAdapter.php';

class ProviderRegistry {

  /** @var VirtualSensorProviderInterface[] */
  private static $adapters;

  /**
   * @return VirtualSensorProviderInterface[]
   */
  public static function getConfiguredAdapters(): array {
    if (self::$adapters === null) {
      self::$adapters = [];
      foreach ([new OpenAQAdapter(), new OpenWeatherAdapter(), new AQICNAdapter()] as $adapter) {
        if ($adapter->isConfigured()) {
          self::$adapters[$adapter->getProviderKey()] = $adapter;
        }
      }
    }
    return self::$adapters;
  }

  /**
   * Get one adapter by key.
   */
  public static function get(string $providerKey): ?VirtualSensorProviderInterface {
    $all = self::getConfiguredAdapters();
    return $all[$providerKey] ?? null;
  }
}
