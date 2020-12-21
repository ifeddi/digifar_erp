<?php

namespace Drupal\odoo_sync\Form;


use Drupal\Component\Render\HtmlEscapedText;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\node\Entity\NodeType;
use Drupal\odoo_api\FormStateCacheTrait;
use Drupal\odoo_api\OdooApi\Client;
use Drupal\odoo_sync\Traits\FieldCreationTrait;
use Drupal\odoo_sync\Traits\NodeTypeCreationTrait;
use fXmlRpc\Exception\ExceptionInterface as XmlRpcException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class OdooConfigForm contains all functions related to the Odoo Configuration.
 *
 * @package Drupal\odoo_sync\Form
 */
class OdooConfigForm extends FormBase
{

  use FormStateCacheTrait;
  use NodeTypeCreationTrait;
  use FieldCreationTrait;

  /**
   * Odoo API client service.
   *
   * @var \Drupal\odoo_api\OdooApi\Client
   */
  protected $odooApiApiClient;

  /**
   * Image factory service.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * Import Config.
   *
   * @var string
   */
  public static $configFormName = 'odoo_sync.configuration';

  /**
   * Import Config.
   *
   * @var string
   */
  public static $modelField = 'odoo_models';

  /**
   * Models.
   *
   * @var array
   */
  public static $models = [];

//  /**
//   * Path URL.
//   *
//   * @var string
//   */
//  public static $pathField = 'odoo_api_url';
//
//  /**
//   * Path URL.
//   *
//   * @var string
//   */
//  public static $databaseField = 'odoo_api_database';
//
//  /**
//   * Login field name.
//   *
//   * @var string
//   */
//  public static $loginField = 'odoo_api_login';
//
//  /**
//   * Password field name.
//   *
//   * @var string
//   */
//  public static $passwordField = 'odoo_api_password';

  /**
   * {@inheritdoc}
   */
  public function __construct(Client $odoo_api_api_client, ImageFactory $image_factory)
  {
    $this->odooApiApiClient = $odoo_api_api_client;
    $this->imageFactory = $image_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('odoo_api.api_client'),
      $container->get('image.factory')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public function getFormId()
  {
    return 'odoo_sync_config_form';
  }

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    // Initialize form state cache.
    $this->formState = $form_state;
    // Set config values
    $config = $this->config(static::$configFormName);
    $form[static::$modelField] = [
      '#title' => $this->t('Odoo Models'),
      '#description' => $this->t('List of all models'),
      '#type' => 'select',
      '#options' => $this->getModelsOptions(),
      '#multiple' => TRUE,
      '#size' => 20,
      '#weight' => 0,
      '#default_value' => $config->get(static::$modelField),
    ];

    $form['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
      '#submit' => [[$this, 'saveConfig']],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create models'),
      '#button_type' => 'info',
      '#submit' => [[$this, 'createModels']],
    ];
    $form['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import data'),
      '#ajax' => [
        'callback' => get_class($this) . '::ajaxUpdate',
        'wrapper' => 'metadata-container',
      ],
      '#submit' => [get_class($this) . '::rebuildForm'],
    ];

//    $form['actions']['#type'] = 'actions';
    $selectedModels = $this->getModelsOptions($config->get(static::$modelField));
    if (count($selectedModels) > 0) {
      $form['models'] = [
        '#type' => 'details',
        '#title' => 'Models infos',
        '#description' => $this->t('Get metadata and fields infos of selected models.'),
        '#open' => FALSE,
      ];
      foreach ($selectedModels as $key => $selectedModel) {
        $model = static::$models[$key];
        $form['models']['model' . $key] = [
          '#type' => 'details',
          '#title' => $model['name'],
          '#open' => FALSE,
        ];
        $form['models']['model' . $key]['metadata' . $key] = $this->getModelInfo($model);
        $form['models']['model' . $key]['fields' . $key] = $this->getModelFieldsTable($model);
      }
    }

    return $form;
  }

  /**
   * Save the configs.
   *
   * @param array $form
   *   From object.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form State.
   */
  public function saveConfig(array &$form, FormStateInterface $form_state)
  {
    $config = $this->configFactory->getEditable(static::$configFormName);
    // Set selected models
    $config->set(static::$modelField, $form_state->getValue(static::$modelField));
    // Set selected attributs by models
    $modelField = $config->get(static::$modelField);
    $selectedModels = $this->getModelsOptions($modelField);
    $contents = $form_state->getValue('contents');
    if ($contents && count($contents) > 0) {
      foreach ($contents as $key => $element) {
        if($element && is_array($element)){
          $config->set(key($element), current($element));
        }
      }
    }
    $config->save();
    //parent::submitForm($form, $form_state);
    $this->messenger()->addMessage($this->t('The configuration options have been saved.'));
  }

  /**
   * Import data from odoo database.
   *
   * @param array $form
   *   From object.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form State.
   * @codeCoverageIgnore
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createModels(array &$form, FormStateInterface $form_state)
  {
    $config = $this->configFactory->getEditable(static::$configFormName);
    $modelField = $config->get(static::$modelField);
    $selectedModels = $this->getModelsOptions($modelField);
    if (count($selectedModels) > 0) {
      foreach ($selectedModels as $key => $selectedModel) {
        $model = static::$models[$key];
        $modelMachineName = str_replace(['.'], '_', $model['model']);
        // Creation of model
        $nodeType = $this->createNodeType($model['name'], $modelMachineName, new HtmlEscapedText($model['info']));
        $this->messenger()->addMessage($this->t('Model "'.$model['name'].'" has been imported'),$this->messenger()::TYPE_STATUS);
        // Import fields
        foreach ($this->getModelFieldsData($model) as $fieldName => $field) {
          $fieldName = new HtmlEscapedText($fieldName);
          $fieldName = 'm' . $key . '_' . $fieldName;
          $fieldName = substr($fieldName,0, 32);
          //$fieldName = $modelMachineName . '_' . $fieldName;
          // Text fieal
          $description = isset($field['help']) ? $field['help'] : '';
          switch ($field['type']) {
            case 'char':
              $this->addField($fieldName, $field['string'], $description, 'string', $modelMachineName);
              break;
            case 'text':
              $this->addField($fieldName, $field['string'], $description,'string_long', $modelMachineName);
              break;
            case 'integer':
              $this->addField($fieldName, $field['string'], $description,'integer', $modelMachineName);
              break;
            case 'float':
              $this->addField($fieldName, $field['string'], $description,'float', $modelMachineName);
              break;
            case 'boolean':
              $this->addField($fieldName, $field['string'], $description,'boolean', $modelMachineName);
              break;
            case 'boolean':
              $this->addField($fieldName, $field['string'], $description,'boolean', $modelMachineName);
              break;
            case 'date':
              $this->addField($fieldName, $field['string'], $description,'datetime', $modelMachineName);
              break;
            case 'many2one':
              $this->addField($fieldName, $field['string'], $description,'text_long', $modelMachineName);
              break;
            case 'one2many':
              $this->addField($fieldName, $field['string'], $description,'text_long', $modelMachineName);
              break;
            case 'many2many':
              $this->addField($fieldName, $field['string'], $description,'text_long', $modelMachineName);
              break;
            case 'selection':
              $this->addField($fieldName, $field['string'], $description,'string', $modelMachineName);
              break;
            case 'binary':
              $this->addField($fieldName, $field['string'], $description,'file', $modelMachineName);
              break;
            default:
              $this->messenger()->addMessage($this->t('Field "'.$field['string'].'" of model "'.$model['name'].'" cannot be created!'),$this->messenger()::TYPE_WARNING);
              $data = [];
          }
        }
      }
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  protected function getEditableConfigNames()
  {
    return [
      'odoo_sync.configuration',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Do nothing.
  }

  /**
   * Ajax callback returning the import data result.
   *
   * @param $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax response containing the conflict resolution UI.
   */
  public static function ajaxUpdate(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();
    $response->setStatusCode(200);
    $response->addCommand(new MessageCommand('There was a problem. Please save your work and go outside.',
      NULL,
      ['type' => 'warning'])
    );
    return $response;
  }

  /**
   * Form submit callback for rebuilding the form.
   */
  public static function rebuildForm(array &$form, FormStateInterface $form_state)
  {
    $form_state->setRebuild();
  }

  /**
   * Gets metadata for models.
   *
   * @return array
   *   List of models.
   */
  protected function getCachedModelsList()
  {
    return $this->cacheResponse('models_list', function () {
      foreach ($this->odooApiApiClient->searchRead('ir.model') as $model) {
        static::$models[$model['id']] = $model;
      }
      return static::$models;
    });

  }

  /**
   * Gets list of models for select element.
   *
   * @return array
   *   List of models.
   */
  protected function getModelsOptions(array $selectedIds = [])
  {
    $options = [];
    foreach ($this->getCachedModelsList() as $id => $model) {
      if (!empty($selectedIds) && !in_array($id, array_keys($selectedIds), true)) {
        continue;
      }
      $options[$id] = $model['name'] . ' (' . $model['model'] . ')';
    }
    //ksort($options);
    return $options;
  }

  /**
   * Gets model metadata render array.
   *
   * @param array $model
   *   Model definition.
   *
   * @return array
   *   Render array.
   */
  protected function getModelMetadata(array $model)
  {
    $element = [
      'info' => $this->getModelInfo($model),
//      'search' => $this->searchForm($model),
//      'fields' => $this->getModelFieldsTable($model),
    ];

    return $element;
  }

  /**
   * Gets Odoo model info table.
   *
   * @param array $model
   *   Model definition.
   *
   * @return array
   *   Render array.
   */
  protected function getModelInfo(array $model)
  {
    $table = [
      '#type' => 'details',
      '#title' => $this->t('Model info : ') . ' ' . $model['model'],
      '#open' => FALSE,
      'contents' => [
        '#type' => 'table',
        [
          'label' => ['#plain_text' => $this->t('Model machine name')],
          'value' => ['#plain_text' => $model['model']],
        ],
        [
          'label' => ['#plain_text' => $this->t('Model name')],
          'value' => ['#plain_text' => $model['name']],
        ],
        [
          'label' => ['#plain_text' => $this->t('Display name')],
          'value' => ['#plain_text' => $model['display_name']],
        ],
        [
          'label' => ['#plain_text' => $this->t('Info')],
          'value' => ['#markup' => nl2br(new HtmlEscapedText($model['info']))],
        ],
        [
          'label' => ['#plain_text' => $this->t('Model state')],
          'value' => ['#plain_text' => $model['state']],
        ],
        [
          'label' => ['#plain_text' => $this->t('Modules')],
          'value' => ['#plain_text' => $model['modules']],
        ],
      ],
    ];

    return $table;
  }
  function search($array, $key, $value) {
    $results = array();

    if (is_array($array)) {
      if (isset($array[$key]) && $array[$key] == $value) {
        $results[] = $array;
      }

      foreach ($array as $subarray) {
        $results = array_merge($results, search($subarray, $key, $value));
      }
    }

    return $results;
  }
  /**
   * Gets Odoo model fields table.
   *
   * @param array $model
   *   Model definition.
   *
   * @return array
   *   Render array.
   */
  protected function getModelFieldsTable(array $model)
  {
    $table = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Model fields') . ' : ' . $model['model'],
      'contents' => [
        '#type' => 'table',
        '#header' => [
          'selected' => $this->t('Selected'),
          'field' => $this->t('Name'),
          'name' => $this->t('Machine name'),
          'type' => $this->t('Type'),
          'help' => $this->t('Help'),
        ],
      ],
    ];
    $config = $this->configFactory->getEditable(static::$configFormName);
    foreach ($this->getModelFieldsData($model) as $field_name => $field) {
      $row = [
        $model['id'].$field_name => [
          '#type' => 'checkbox',
          '#default_value' => $config->get($model['id'].$field_name),
          '#title' => $field_name,
          '#title_display' => 'invisible',
        ],
        'field' => ['#markup' => '<pre>' . new HtmlEscapedText($field_name) . '</pre>'],
        'name' => ['#plain_text' => $field['string'] ?: ''],
        'type' => ['#plain_text' => $field['type'] ?: ''],
        'help' => ['#plain_text' => isset($field['help']) ? $field['help'] : ''],
      ];
//      if ($search_result['count'] > 0) {
//        $row['value'] = $this->formatFieldValue($search_result, $field_name, $field);
//      }
      $table['contents'][] = $row;
    }

    return $table;
  }

  /**
   * Gets list of model fields.
   *
   * @param array $model
   *   Model definition.
   *
   * @return array
   *   List of fields.
   */
  protected function getModelFieldsData(array $model)
  {
    $model_name = $model['model'];
    return $this->cacheResponse('models_' . $model_name, function () use ($model_name) {
      return $this->odooApiApiClient->fieldsGet($model_name);
    });
  }

  /**
   * Builds objects search form.
   *
   * @param array $model
   *   Model definition.
   *
   * @return array
   *   Search sub-form render array.
   */
  protected function searchForm(array $model)
  {
    $search = [
      '#type' => 'fieldset',
      '#title' => $this->t('Model search'),
      '#tree' => TRUE,
    ];

    $search['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Field'),
      '#options' => $this->getFieldsOptions($model),
    ];

    $search['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Value'),
    ];

    $search['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#ajax' => [
        'callback' => get_class($this) . '::ajaxUpdate',
        'wrapper' => 'metadata-container',
      ],
      '#submit' => [get_class($this) . '::rebuildForm'],
    ];

    $search['search_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use search cache'),
      '#default_value' => TRUE,
    ];

    return $search;
  }

  /**
   * Gets options for model field select element.
   *
   * @param string $model
   *   Model name.
   *
   * @return array
   *   Array of fields options.
   */
  protected function getFieldsOptions($model)
  {
    $options = [];
    foreach ($this->getModelFieldsData($model) as $field_name => $field) {
      $options[$field_name] = '[' . $field_name . '] ' . $field['string'];
    }
    return $options;
  }

  /**
   * Gets Odoo search results.
   *
   * @return array|false
   *   An array with search results or FALSE if searched model is not set.
   *   The return array has the following fields:
   *     - count: number of records matching the filter,
   *     - item: searched item,
   *     - error: exception or NULL.
   */
  protected function getSearchResult()
  {
    $form_state = $this->formState;

    if (!($model_name = $form_state->getValue('model'))) {
      return FALSE;
    }

    $filter = [];
    $search_field = $form_state->getValue(['search', 'field']);
    $search_value = $form_state->getValue(['search', 'value']);
    if ($search_field && $search_value) {
      $filter[] = [$search_field, '=', $search_value];
    }

    $cache_key = 'model_search_' . $model_name . '_' . md5(serialize($filter));
    return $this->cacheResponse($cache_key, function () use ($model_name, $filter) {
      // @TODO: Implement pager.
      try {
        $count = $this->odooApiApiClient->count($model_name, $filter);
        return [
          'count' => $count,
          // D not run searchRead if we know there are no objects.
          'item' => $count > 0 ? $this->odooApiApiClient->searchRead($model_name, $filter, NULL, NULL, 1)[0] : [],
          'error' => NULL,
        ];
      } catch (XmlRpcException $e) {
        return [
          'count' => 0,
          'item' => [],
          'error' => $e->getMessage(),
        ];
      }
    }, $form_state->getValue(['search', 'search_cache'], TRUE));
  }

  /**
   * Formats Odoo field for output.
   *
   * @param array $search_result
   *   Search results.
   * @param string $field_name
   *   Field name.
   * @param array $field
   *   Field definition.
   *
   * @return array
   *   Field value render array.
   */
  protected function formatFieldValue(array $search_result, $field_name, array $field)
  {
    if ($field['type'] == 'binary'
      && !empty($search_result['item'][$field_name])) {
      $field_value = &$search_result['item'][$field_name];
      if ($binary_data = base64_decode($field_value)) {
        $uri = 'temporary://odoo_binary_file_' . md5($binary_data) . '.png';
        file_put_contents($uri, $binary_data);
        $image = $this->imageFactory->get($uri);

        if ($image->isValid()) {
          return [
            '#theme' => 'image',
            '#width' => $image->getWidth(),
            '#height' => $image->getHeight(),
            '#uri' => 'data:' . $image->getMimeType() . ';base64,' . $field_value,
          ];
        } else {
          return [
            '#markup' => '<pre>' . $this->formatPlural(strlen($field_value), 'Unknown base64 binary data, @length bytes', 'Unknown binary data, @length bytes.') . '</pre>',
          ];
        }
      } else {
        return [
          '#markup' => '<pre>' . $this->formatPlural(strlen($field_value), 'Unknown binary data, @length bytes', 'Unknown binary data, @length bytes.') . '</pre>',
        ];
      }
    }

    $value = isset($search_result['item'][$field_name]) ?
      var_export($search_result['item'][$field_name], TRUE) :
      $this->t('Not set');

    return ['#markup' => '<pre>' . new HtmlEscapedText($value) . '</pre>'];
  }
}
