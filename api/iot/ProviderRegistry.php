<?php
/**
 * Registry of virtual sensor providers. Returns only configured adapters.
 */
namespace CivicAI\Iot;

require_once __DIR__ . '/VirtualSensorProviderInterface.php';
require_once __DIR__ . '/AbstractProvider.php';
require_once __DIR__ . '/OpenAQAdapter.php';
require_once __DIR__ . '/OpenWeatherAdapter.php';
if (is_file(__DIR__ . '/AQICNAdapter.php')) {
  require_once __DIR__ . '/AQICNAdapter.php';
}
if (is_file(__DIR__ . '/WeatherXMAdapter.php')) {
  require_once __DIR__ . '/WeatherXMAdapter.php';
}

class ProviderRegistry {

  /** @var VirtualSensorProviderInterface[] */
  private static $adapters;

  /**
   * @return VirtualSensorProviderInterface[]
   */
  public static function getConfiguredAdapters(): array {
    if (self::$adapters === null) {
      self::$adapters = [];
      $adapterClasses = [
        OpenAQAdapter::class,
        OpenWeatherAdapter::class,
      ];
      if (class_exists('CivicAI\Iot\AQICNAdapter', false)) {
        $adapterClasses[] = \CivicAI\Iot\AQICNAdapter::class;
      }
      if (class_exists('CivicAI\Iot\WeatherXMAdapter', false)) {
        $adapterClasses[] = \CivicAI\Iot\WeatherXMAdapter::class;
      }
      foreach ($adapterClasses as $class) {
        try {
          $adapter = new $class();
          if ($adapter->isConfigured()) {
            self::$adapters[$adapter->getProviderKey()] = $adapter;
          }
        } catch (Throwable $e) {
          // skip broken adapter, avoid 500
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
