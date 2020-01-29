<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\strawberryfield\StrawberryfieldFilePersisterService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Utility\Unicode;


/**
 * Event subscriber for SBF bearing entity presave event.
 */
class StrawberryfieldEventPresaveSubscriberSetTitlefromMetadata extends StrawberryfieldEventPresaveSubscriber {

  use StringTranslationTrait;

  /**
   * @var int
   */
  protected static $priority = -900;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;


  /**
   * The Storage Destination Scheme.
   *
   * @var string;
   */
  protected $destinationScheme = NULL;

  /**
   * StrawberryfieldEventPresaveSubscriberFilePersister constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory

  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
  }


  /**
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function onEntityPresave(StrawberryfieldCrudEvent $event) {

    /* @var $entity \Drupal\Core\Entity\ContentEntityBase */
    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();
    $titleadded = NULL;
    $forceupdate = TRUE;
    if (!$entity->isNew()) {
      if ($entity->original->getTitle() != $entity->getTitle()) {
        // Means someone manually, via a Title Widget, changed the title
        // If so, enforce that and don't try to overwrite.
        // But, webform widget, if updating title automatically is set, should
        // unset the 'title', allowing us to always get metadata title into
        // the node one. Still, allowing other widgets to set titles without us
        // overriding it. Smart?
        // Reality is, if you don't use Title, just leave it out.
        $forceupdate = FALSE;
      }
    }

    if (!$entity->getTitle() || $forceupdate) {
      foreach ($sbf_fields as $field_name) {
        /* @var $field \Drupal\Core\Field\FieldItemInterface */
        $field = $entity->get($field_name);
        /* @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */
        // This will try with any possible match.
        foreach ($field->getIterator() as $delta => $itemfield) {
          $flat = $itemfield->provideFlatten();
          if (isset($flat['label'])) {
            // Flattener should always give me an array?
            if (is_array($flat['label'])) {
              $title = reset($flat['label']);
            }
            else {
              $title = $flat['label'];
            }
            if (strlen(trim($title)) > 0) {
              $title = Unicode::truncate($title,128,TRUE,TRUE, 24);
              $entity->setTitle($title);
              $titleadded = $title;
              break 2;
            }
          }
        }
      }
      if ($titleadded) {
        $this->messenger->addStatus(
          $this->t(
            'Your New Object title is @title',
            ['@title' => $titleadded ]
          )
        );
      } else {
        // Means we need a title, got nothing from metadata or node, dealing with it.
        $title = $this->t('New Untitled Archipelago Digital Object by @author', ['@author' => $entity->getOwner()->getDisplayName()]);
        $entity->setTitle($title);
      }
      $current_class = get_called_class();
      $event->setProcessedBy($current_class, TRUE);
    }
  }
}