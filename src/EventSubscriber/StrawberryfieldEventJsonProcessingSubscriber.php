<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\StrawberryfieldEventType;
use Drupal\strawberryfield\Event\StrawberryfieldJsonProcessEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for SBF bearing entity json process event.
 */
abstract class StrawberryfieldEventJsonProcessingSubscriber implements EventSubscriberInterface {

  /**
   * @var int
   */
  protected static $priority = 0;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    // @TODO check event priority and adapt to future D9 needs.
    $events[StrawberryfieldEventType::JSONPROCESS][] = ['onJsonInvokeProcess', self::$priority];
    return $events;
  }

  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldJsonProcessEvent $event
   *   The event.
   */
  abstract public function onJsonInvokeProcess(StrawberryfieldJsonProcessEvent $event);

}
