<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 9:26 PM
 */

namespace Drupal\strawberryfield\Plugin\Field\FieldWidget;

use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Drupal\Component\Utility\Environment;
use Drupal\file\Element\ManagedFile;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\strawberryfield\Tools\JsonSimpleXMLElementDecorator;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Render\Element;
use Drupal\Core\File;
use Drupal\Component\Utility\Bytes;
/**
 * Plugin implementation of the 'strawberry_file_xml' widget.
 *
 * @FieldWidget(
 *   id = "strawberry_file_xml",
 *   label = @Translation("XML Upload and Importer for Strawberry Field"),
 *   field_types = {
 *     "strawberryfield_field"
 *   }
 * )
 */
class StrawberryXmlSourceWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  use MessengerTrait;

  /**
   * The Storage Destination Scheme.
   *
   * @var string;
   */
  protected $destinationScheme = NULL;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, ElementInfoManagerInterface $element_info, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->elementInfo = $element_info;
    $this->destinationScheme = $config_factory->get(
      'strawberryfield.storage_settings'
    )->get('file_tmp_scheme');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('element_info'),
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'placeholder' => '',
        'progress_indicator' => 'throbber',
        'file_number' => 1,
        'file_directory' => 'sbf_tmp',
        'max_filesize' => 2,
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['progress_indicator'] = [
      '#type' => 'radios',
      '#title' => t('Progress indicator'),
      '#options' => [
        'throbber' => t('Throbber'),
        'bar' => t('Bar with progress meter'),
      ],
      '#default_value' => $this->getSetting('progress_indicator'),
      '#description' => t('The throbber display does not show the status of uploads but takes up less space. The progress bar is helpful for monitoring progress on large uploads.'),
      '#weight' => 16,
      '#access' => file_progress_implementation(),
    ];
    $element['file_number'] = [
      '#type' => 'number',
      '#title' => t('Number of files user can upload'),
      '#default_value' => $this->getSetting('file_number') ? $this->getSetting('file_number') : 1,
      '#weight' => 17,
    ];

    $element['file_directory'] = [
      '#type' => 'textfield',
      '#title' => t('Upload folder inside @target', ['@target' => $this->destinationScheme]),
      '#default_value' => $this->getSetting('upload_folder') ? $this->getSetting('file_directory') : 'sbf_temp',
      '#weight' => 17,
    ];
    $element['max_filesize'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum file size'),
      '#field_suffix' => $this->t('MB (Max: @filesize MB)', ['@filesize' => $this->getSetting('max_filesize')]),
      '#placeholder' => $this->getSetting('max_filesize'),
      '#description' => $this->t('Enter the max file size a user may upload.'),
      '#min' => 1,
      '#max' => $this->getSetting('max_filesize'),
      '#step' => 'any',
    ];


    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = t('Progress indicator: @progress_indicator', ['@progress_indicator' => $this->getSetting('progress_indicator')]);
    $summary[] = t('Allowed Number of XML files user can upload: @file_number', ['@file_number' => $this->getSetting('file_number')]);
    return $summary;
  }

  /**
   * Overrides \Drupal\Core\Field\WidgetBase::formMultipleElements().
   *
   * Special handling for draggable multiple widgets and 'add more' button.
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $form['#parents'];

    // Load the items for form rebuilds from the field state as they might not
    // be in $form_state->getValues() because of validation limitations. Also,
    // they are only passed in as $items when editing existing entities.
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    if (isset($field_state['items'])) {
      //$items->setValue($field_state['items']);
    }

    // Determine the number of widgets to display.
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    switch ($cardinality) {
      case FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED:
        $max = count($items);
        $is_multiple = TRUE;
        break;

      default:
        $max = $cardinality - 1;
        $is_multiple = ($cardinality > 1);
        break;
    }

    $title = $this->fieldDefinition->getLabel();
    $description = $this->getFilteredDescription();

    $elements = [];

    $delta = 0;
    // Add an element for every existing item.
    foreach ($items as $item) {
      $element = [
        '#title' => $title,
        '#description' => $description,
      ];
      $element = $this->formSingleElement($items, $delta, $element, $form, $form_state);

      if ($element) {
        // Input field for the delta (drag-n-drop reordering).
        if ($is_multiple) {
          // We name the element '_weight' to avoid clashing with elements
          // defined by widget.
          $element['_weight'] = [
            '#type' => 'weight',
            '#title' => t('Weight for row @number', ['@number' => $delta + 1]),
            '#title_display' => 'invisible',
            // Note: this 'delta' is the FAPI #type 'weight' element's property.
            '#delta' => $max,
            '#default_value' => $item->_weight ?: $delta,
            '#weight' => 100,
          ];
        }

        $elements[$delta] = $element;
        $delta++;
      }
    }

    $empty_single_allowed = ($cardinality == 1 && $delta == 0);
    $empty_multiple_allowed = ($cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED || $delta < $cardinality) && !$form_state->isProgrammed();

    // Add one more empty row for new uploads except when this is a programmed
    // multiple form as it is not necessary.
    if ($empty_single_allowed || $empty_multiple_allowed) {
      // Create a new empty item.
      $items->appendItem();
      $element = [
        '#title' => $title,
        '#description' => $description,
      ];
      $element = $this->formSingleElement($items, $delta, $element, $form, $form_state);
      if ($element) {
        $element['#required'] = ($element['#required'] && $delta == 0);
        $elements[$delta] = $element;
      }
    }

    if ($is_multiple) {
      // The group of elements all-together need some extra functionality after
      // building up the full list (like draggable table rows).
      $elements['#file_upload_delta'] = $delta;
      $elements['#type'] = 'details';
      $elements['#open'] = TRUE;
      $elements['#theme'] = 'file_widget_multiple';
      $elements['#theme_wrappers'] = ['details'];
      $elements['#process'] = [[get_class($this), 'processMultiple']];
      $elements['#title'] = $title;

      $elements['#description'] = $description;
      $elements['#field_name'] = $field_name;
      $elements['#language'] = $items->getLangcode();
      $elements['#display_field'] = NULL;

      // Add some properties that will eventually be added to the file upload
      // field. These are added here so that they may be referenced easily
      // through a hook_form_alter().
      $elements['#file_upload_title'] = t('Add a new file');
      $elements['#file_upload_description'] = [
        '#theme' => 'file_upload_help',
        '#description' => '',
        '#upload_validators' => $elements[0]['#upload_validators'],
        '#cardinality' => $cardinality,
      ];
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_settings = $this->getFieldSettings();
    $entity_type = $items->getEntity()->getEntityTypeId();

    if ($items->getEntity()->isNew()) {
      $form_state->set('strawberry_file_xml_isnew', true);
      $entity_uuid = NULL;
      $entity_id = NULL;
    } else {
      $form_state->set('strawberry_file_xml_isnew', false);
    }

    $cardinality = $this->getSetting('file_number');
    // Since we are reusing the managed file widget
    // We need to set the display, which is not needed for a SBF.
    // 0 Means don't display.
    $defaults = [
      'fids' => [],
      'display' => 0,
      'description' => '',
    ];


    $element_info = $this->elementInfo->getInfo('managed_file');
    $element += [
      'upload' => [
        '#type' => 'managed_file',
        '#upload_location' => $this->getUploadLocation(),
        '#upload_validators' => $this->getUploadValidators(),
        '#value_callback' => [get_class($this), 'value'],
        '#process' => array_merge($element_info['#process'], [[get_class($this), 'process']]),
        '#progress_indicator' => $this->getSetting('progress_indicator'),
        // Allows this field to return an array instead of a single value.
        '#extended' => TRUE,
        // Add properties needed by value() and process() methods.
        '#field_name' => $this->fieldDefinition->getName(),
        '#display_field' => NULL,
        '#display_default' => FALSE,
        '#description_field' => 0,
        '#entity_type' => $entity_type,
        '#cardinality' => $cardinality,
      ]
    ];

    $element['#weight'] = $delta;

    // Fetch data from SBF

    $file_ids = [];
    $rawjson = '{}';
    if (!$items[$delta]->isEmpty()) {
      // ::value just string, ::getValue() array with value key.
      $rawjson = $items[$delta]->value;
      $arrayjson = json_decode($rawjson, TRUE);
      $json_error = json_last_error();
      if ($json_error == JSON_ERROR_NONE) {
        // @TODO make the upload key for the file configurable
        $file_ids = (isset($arrayjson['file_upload_xml']) && !empty($arrayjson['file_upload_xml'])) ? (array) $arrayjson['file_upload_xml']  : [];
      }
      else {
        // This should never happen since the basefield has a JSON symfony validator.
        $this->messenger()->addError(
          $this->t(
            'Looks like your stored field data is not in JSON format.<br> JSON says: @jsonerror <br>. Please correct it!',
            [
              '@jsonerror' => json_last_error_msg()
            ]
          )
        );
        return [];
      }
    }

    $files_in_sbf = [
      'fids' => $file_ids,
      'display' => 0,
      'description' => '',
    ];

    $element['upload']['#default_value'] = $files_in_sbf + $defaults;

    $default_fids = $element['upload']['#extended'] ? $element['upload']['#default_value']['fids'] : $element['upload']['#default_value'];
    if (empty($default_fids)) {
      $file_upload_help = [
        '#theme' => 'file_upload_help',
        '#description' => t('Upload XML file to be imported as metadata into this ADO.'),
        '#upload_validators' => $element['upload']['#upload_validators'],
        '#cardinality' => $cardinality,
      ];
      $element['upload']['#description'] = \Drupal::service('renderer')->renderPlain($file_upload_help);
      $element['upload']['#multiple'] = $cardinality != 1 ? TRUE : FALSE;
      if ($cardinality != 1 && $cardinality != -1) {
        $element['upload']['#element_validate'] = [[get_class($this), 'validateMultipleCount']];
      }
    }

    // Add current RAW JSON if any
    $element['strawberry_file_xml']['json'] = [
      '#type' => 'value',
      '#default_value' => $rawjson,
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // Since file upload widget now supports uploads of more than one file at a
    // time it always returns an array of fids. We have to translate this to a
    // single fid, as field expects single value.
    $new_values = [];
    $values2 = [];
    foreach($values as $delta => $itemvalue) {
      $file_id_list = [];
      $file_id_list = $values[$delta]['upload']['fids'];

      // Since our operation is based on loading the XML from a file
      // Converting into JSON
      // But also preserving any other existing JSON from this field
      // We will here load the
      $jsonarray = json_decode(
        $values[$delta]['strawberry_file_xml']['json'],
        TRUE
      );


      // Get the content of each XML file and parse it
      try {
        $files = $this->entityTypeManager->getStorage('file')->loadMultiple(
          $file_id_list
        );
      } catch (InvalidPluginDefinitionException $e) {
        $this->messenger()->addError(
          $this->t(
            'Sorry, we had real issues loading your files. Invalid Plugin File Definition.'
          )
        );

      } catch (PluginNotFoundException $e) {
        $this->messenger()->addError(
          $this->t(
            'Sorry, we had real issues loading your files. File Plugin not Found'
          )
        );
      }

      $xmltojson = '';
      foreach ($files as $file) {
        $uri = $file->getFileUri();
        $data = file_get_contents($uri);
        $internalErrors = libxml_use_internal_errors(TRUE);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $simplexml = simplexml_load_string($data);
        if ($simplexml === FALSE) {
          $messages = $this->getXmlErrors($internalErrors);
          if (empty($messages)) {
            $this->messenger()->addError(
              $this->t(
                'Sorry, the provided File @filename does not contain valid XML',
                ['@filename' => $file->getFileName()]
              )
            );
          }
          else {
            $this->messenger()->addError(
              $this->t(
                'Sorry, the provided File @filename XML has following errors @messages',
                [
                  '@filename' => $file->getFileName(),
                  '@messages' => implode("\n", $messages)
                ]
              )
            );
          }
        }
        else {
          // Root key is
          $rootkey = $simplexml->getName();
          $xmltojson = new JsonSimpleXMLElementDecorator($simplexml, TRUE, TRUE, 5);
          // Destination key
          $xmljsonstring = json_encode($xmltojson, JSON_PRETTY_PRINT);
          $xmljsonarray =  json_decode($xmljsonstring, TRUE);
          $jsonarray['ap:importeddata'][$rootkey] = $xmljsonarray;
        }
      }
        if ($xmltojson) {
          $jsonarray['file_upload_xml'] = $file_id_list;
          $entity_mapping_structure = isset($jsonarray['ap:entitymapping']) ? $jsonarray['ap:entitymapping'] : [];
          $entity_mapping_structure['entity:file'][] = 'file_upload_xml';
          $entity_mapping_structure['entity:file'] = array_unique($entity_mapping_structure['entity:file'],SORT_STRING);
          $jsonarray['ap:entitymapping'] = $entity_mapping_structure;
          $processedAsValues = \Drupal::service(
            'strawberryfield.file_persister'
          )
            ->generateAsFileStructure(
              $file_id_list,
              'file_upload_xml',
              $jsonarray
            );
          $jsonarray = array_merge_recursive($jsonarray, $processedAsValues);
        }

        $jsonvalue = json_encode($jsonarray, JSON_PRETTY_PRINT);
        $values2[$delta]['value'] = $jsonvalue;
      }

      return parent::massageFormValues($values2, $form, $form_state);

    }

    /**
     * {@inheritdoc}
     */
    public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
      parent::extractFormValues($items, $form, $form_state);

      // Update reference to 'items' stored during upload to take into account
      // changes to values like 'alt' etc.
      // @see \Drupal\file\Plugin\Field\FieldWidget\FileWidget::submit()
      $field_name = $this->fieldDefinition->getName();
      $field_state = static::getWidgetState($form['#parents'], $field_name, $form_state);
      $field_state['items'] = $items->getValue();
      static::setWidgetState($form['#parents'], $field_name, $form_state, $field_state);
    }

    /**
     * Form API callback. Retrieves the value for the file_generic field element.
     *
     * This method is assigned as a #value_callback in formElement() method.
     */
    public static function value($element, $input, FormStateInterface $form_state) {
      if ($input) {
        // Checkboxes lose their value when empty.
        // If the display field is present make sure its unchecked value is saved.
        if (empty($input['display'])) {
          $input['display'] = $element['#display_field'] ? 0 : 1;
        }
      }

      // We depend on the managed file element to handle uploads.
      $return = ManagedFile::valueCallback($element, $input, $form_state);

      // Ensure that all the required properties are returned even if empty.
      $return += [
        'fids' => [],
        'display' => 0,
        'description' => '',
      ];

      return $return;
    }

    /**
     * Form element validation callback for upload element on file widget. Checks
     * if user has uploaded more files than allowed.
     *
     * This validator is used only when cardinality not set to 1 or unlimited.
     */
    public static function validateMultipleCount($element, FormStateInterface $form_state, $form) {
      $values = NestedArray::getValue($form_state->getValues(), $element['#parents']);

      $array_parents = $element['#array_parents'];
      array_pop($array_parents);
      $previously_uploaded_count = count(Element::children(NestedArray::getValue($form, $array_parents))) - 1;

      $field_storage_definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions($element['#entity_type']);
      $field_storage = $field_storage_definitions[$element['#field_name']];
      $newly_uploaded_count = count($values['fids']);
      $total_uploaded_count = $newly_uploaded_count + $previously_uploaded_count;
      if ($total_uploaded_count > $field_storage->getCardinality()) {
        $keep = $newly_uploaded_count - $total_uploaded_count + $field_storage->getCardinality();
        $removed_files = array_slice($values['fids'], $keep);
        $removed_names = [];
        foreach ($removed_files as $fid) {
          $file = File::load($fid);
          $removed_names[] = $file->getFilename();
        }
        $args = [
          '%field' => $field_storage->getName(),
          '@max' => $field_storage->getCardinality(),
          '@count' => $total_uploaded_count,
          '%list' => implode(', ', $removed_names),
        ];
        $message = t('Field %field can only hold @max values but there were @count uploaded. The following files have been omitted as a result: %list.', $args);
        \Drupal::messenger()->addWarning($message);
        $values['fids'] = array_slice($values['fids'], 0, $keep);
        NestedArray::setValue($form_state->getValues(), $element['#parents'], $values);
      }
    }

    /**
     * Form API callback: Processes a file_generic field element.
     *
     * Expands the file_generic type to include the description and display
     * fields.
     *
     * This method is assigned as a #process callback in formElement() method.
     */
    public static function process($element, FormStateInterface $form_state, $form) {
      $item = $element['#value'];
      $item['fids'] = $element['fids']['#value'];

      // Add the display field if enabled.
      if ($element['#display_field']) {
        $element['display'] = [
          '#type' => empty($item['fids']) ? 'hidden' : 'checkbox',
          '#title' => t('Include file in display'),
          '#attributes' => ['class' => ['file-display']],
        ];
        if (isset($item['display'])) {
          $element['display']['#value'] = $item['display'] ? '1' : '';
        }
        else {
          $element['display']['#value'] = $element['#display_default'];
        }
      }
      else {
        $element['display'] = [
          '#type' => 'hidden',
          '#value' => '1',
        ];
      }

      // Adjust the Ajax settings so that on upload and remove of any individual
      // file, the entire group of file fields is updated together.
      if ($element['#cardinality'] != 1) {
        $parents = array_slice($element['#array_parents'], 0, -1);
        $new_options = [
          'query' => [
            'element_parents' => implode('/', $parents),
          ],
        ];
        $field_element = NestedArray::getValue($form, $parents);
        $new_wrapper = $field_element['#id'] . '-ajax-wrapper';
        foreach (Element::children($element) as $key) {
          if (isset($element[$key]['#ajax'])) {
            $element[$key]['#ajax']['options'] = $new_options;
            $element[$key]['#ajax']['wrapper'] = $new_wrapper;
          }
        }
        unset($element['#prefix'], $element['#suffix']);
      }

      // Add another submit handler to the upload and remove buttons, to implement
      // functionality needed by the field widget. This submit handler, along with
      // the rebuild logic in file_field_widget_form() requires the entire field,
      // not just the individual item, to be valid.
      foreach (['upload_button', 'remove_button'] as $key) {
        $element[$key]['#submit'][] = [get_called_class(), 'submit'];
        $element[$key]['#limit_validation_errors'] = [array_slice($element['#parents'], 0, -1)];
      }

      return $element;
    }

    /**
     * Form API callback: Processes a group of file_generic field elements.
     *
     * Adds the weight field to each row so it can be ordered and adds a new Ajax
     * wrapper around the entire group so it can be replaced all at once.
     *
     * This method on is assigned as a #process callback in formMultipleElements()
     * method.
     */
    public static function processMultiple($element, FormStateInterface $form_state, $form) {
      $element_children = Element::children($element, TRUE);
      $count = count($element_children);

      // Count the number of already uploaded files, in order to display new
      // items in \Drupal\file\Element\ManagedFile::uploadAjaxCallback().
      if (!$form_state->isRebuilding()) {
        $count_items_before = 0;
        foreach ($element_children as $children) {
          if (!empty($element[$children]['#default_value']['fids'])) {
            $count_items_before++;
          }
        }

        $form_state->set('file_upload_delta_initial', $count_items_before);
      }

      foreach ($element_children as $delta => $key) {
        if ($key != $element['#file_upload_delta']) {
          $description = static::getDescriptionFromElement($element[$key]);
          $element[$key]['_weight'] = [
            '#type' => 'weight',
            '#title' => $description ? t('Weight for @title', ['@title' => $description]) : t('Weight for new file'),
            '#title_display' => 'invisible',
            '#delta' => $count,
            '#default_value' => $delta,
          ];
        }
        else {
          // The title needs to be assigned to the upload field so that validation
          // errors include the correct widget label.
          $element[$key]['#title'] = $element['#title'];
          $element[$key]['_weight'] = [
            '#type' => 'hidden',
            '#default_value' => $delta,
          ];
        }
      }

      // Add a new wrapper around all the elements for Ajax replacement.
      $element['#prefix'] = '<div id="' . $element['#id'] . '-ajax-wrapper">';
      $element['#suffix'] = '</div>';

      return $element;
    }

    /**
     * Retrieves the file description from a field field element.
     *
     * This helper static method is used by processMultiple() method.
     *
     * @param array $element
     *   An associative array with the element being processed.
     *
     * @return array|false
     *   A description of the file suitable for use in the administrative
     *   interface.
     */
    protected static function getDescriptionFromElement($element) {
      // Use the actual file description, if it's available.
      if (!empty($element['#default_value']['description'])) {
        return $element['#default_value']['description'];
      }
      // Otherwise, fall back to the filename.
      if (!empty($element['#default_value']['filename'])) {
        return $element['#default_value']['filename'];
      }
      // This is probably a newly uploaded file; no description is available.
      return FALSE;
    }

    /**
     * Form submission handler for upload/remove button of formElement().
     *
     * This runs in addition to and after file_managed_file_submit().
     *
     * @see file_managed_file_submit()
     */
    public static function submit2($form, FormStateInterface $form_state) {
      // During the form rebuild, formElement() will create field item widget
      // elements using re-indexed deltas, so clear out FormState::$input to
      // avoid a mismatch between old and new deltas. The rebuilt elements will
      // have #default_value set appropriately for the current state of the field,
      // so nothing is lost in doing this.
      $button = $form_state->getTriggeringElement();
      error_log(print_r($button['#parents'],true));
      $parents = array_slice($button['#parents'], 0, -1);
      error_log(print_r($parents,true));
      NestedArray::setValue($form_state->getUserInput(), $parents, NULL);

      // Go one level up in the form, to the widgets container.
      $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
      error_log(print_r( $element['#field_name'],true));
      $field_name = $element['#field_name'];
      $parents = $element['#field_parents'];

      $submitted_values = NestedArray::getValue($form_state->getValues(), array_slice($button['#parents'], 0, -1));
      error_log(print_r($submitted_values,true));
      foreach ($submitted_values as $delta => $submitted_value) {
        if (empty($submitted_value['fids'])) {
          unset($submitted_values[$delta]);
        }
      }

      // If there are more files uploaded via the same widget, we have to separate
      // them, as we display each file in its own widget.
      $new_values = [];
      foreach ($submitted_values as $delta => $submitted_value) {
        if (is_array($submitted_value['fids'])) {
          foreach ($submitted_value['fids'] as $fid) {
            $new_value = $submitted_value;
            $new_value['fids'] = [$fid];
            $new_values[] = $new_value;
          }
        }
        else {
          $new_values = $submitted_value;
        }
      }

      // Re-index deltas after removing empty items.
      $submitted_values = array_values($new_values);

      // Update form_state values.
      NestedArray::setValue($form_state->getValues(), array_slice($button['#parents'], 0, -1), $submitted_values);

      // Update items.
      $field_state = static::getWidgetState($parents, $field_name, $form_state);
      $field_state['items'] = $submitted_values;
      static::setWidgetState($parents, $field_name, $form_state, $field_state);
    }

  /**
   * Form submission handler for upload/remove button of formElement().
   *
   * This runs in addition to and after file_managed_file_submit().
   *
   * @see file_managed_file_submit()
   */
  public static function submit($form, FormStateInterface $form_state) {

  }
    /**
     * {@inheritdoc}
     */
    public function flagErrors(FieldItemListInterface $items, ConstraintViolationListInterface $violations, array $form, FormStateInterface $form_state) {
      // Never flag validation errors for the remove button.
      $clicked_button = end($form_state->getTriggeringElement()['#parents']);
      if ($clicked_button !== 'remove_button') {
        parent::flagErrors($items, $violations, $form, $form_state);
      }
    }


    /**
     * Determines the URI for a file field.
     *
     * @param array $data
     *   An array of token objects to pass to Token::replace().
     *
     * @return string
     *   An unsanitized file directory URI with tokens replaced. The result of
     *   the token replacement is then converted to plain text and returned.
     *
     * @see \Drupal\Core\Utility\Token::replace()
     */
    public function getUploadLocation() {
      $settings = $this->getSettings();
      $destination = trim($settings['file_directory'], '/');

      return $this->destinationScheme . '://' . $destination;
    }

    /**
     * Retrieves the upload validators for a file.
     *
     * @return array
     *   An array suitable for passing to file_save_upload() or the file field
     *   element's '#upload_validators' property.
     */
    public function getUploadValidators() {
      $validators = [];
      $settings = $this->getSettings();

      // Cap the upload size according to the PHP limit.
      $max_filesize =  Environment::getUploadMaxSize();
      if (!empty($settings['max_filesize'])) {
        $max_filesize = min($max_filesize, Bytes::toInt($settings['max_filesize']));
      }

      // There is always a file size limit due to the PHP server limit.
      $validators['file_validate_size'] = [$max_filesize];
      $validators['file_validate_extensions'] = ['xml'];
      return $validators;
    }

    /**
     * Returns the XML errors of the internal XML parser.
     *
     * @param bool $internalErrors
     *
     * @return array An array of errors
     */
    private function getXmlErrors($internalErrors)
    {
      $errors = [];
      foreach (libxml_get_errors() as $error) {
        $errors[] = sprintf('[%s %s] %s (in %s - line %d, column %d)',
          LIBXML_ERR_WARNING == $error->level ? 'WARNING' : 'ERROR',
          $error->code,
          trim($error->message),
          $error->file ?: 'n/a',
          $error->line,
          $error->column
        );
      }

      libxml_clear_errors();
      libxml_use_internal_errors($internalErrors);

      return $errors;
    }

  }
