<?php

namespace Drupal\robokassa\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the Off-site Robokassa payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "robokassa_payment",
 *   label = "Robokassa payment",
 *   display_label = "Robokassa",
 *   forms = {
 *     "offsite-payment" = "Drupal\robokassa\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "mir", "jcb", "unionpay", "mastercard", "visa",
 *   },
 * )
 */
class RobokassaPayment extends OffsitePaymentGatewayBase implements RobokassaPaymentInterface
{

  /**
   * The price rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The http cleint.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, RounderInterface $rounder, LanguageManagerInterface $language_manager, Client $http_client, LoggerInterface $logger)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->rounder = $rounder;
    $this->languageManager = $language_manager;
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('commerce_price.rounder'),
      $container->get('language_manager'),
      $container->get('http_client'),
      $container->get('logger.factory')->get('robokassa')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    return [
        'MrchLogin' => '',
        'pass1' => '',
        'pass2' => '',
        'hash_type' => 'md5',
        'allowed_currencies' => [],
        'logging' => '',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['MrchLogin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Идентификатор клиента'),
      '#description' => t('Your robokassa login'),
      '#default_value' => $this->configuration['MrchLogin'],
      '#required' => TRUE,
    ];

    $form['pass1'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Пароль №1'),
      '#description' => t('Password 1'),
      '#default_value' => $this->configuration['pass1'],
      '#required' => TRUE,
    ];

    $form['pass2'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Пароль №2'),
      '#description' => t('Password 2'),
      '#default_value' => $this->configuration['pass2'],
      '#required' => TRUE,
    ];

    $form['hash_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Hash type'),
      '#options' => [
        'md5' => 'MD5',
        'ripemd160' => 'RIPEMD160',
        'sha1' => 'SHA1',
        'sha256' => 'SHA256',
        'sha384' => 'SHA384',
        'sha512' => 'SHA512',
      ],
      '#default_value' => $this->configuration['hash_type'],
      '#required' => TRUE,
    ];

    $form['country'] = [
      '#type' => 'select',
      '#title' => $this->t('Страна магазина'),
      '#options' => [
        'RU' => 'Россия',
        'KZ' => 'Казахстан',
      ],
      '#default_value' => $this->configuration['country'],
      '#required' => TRUE,
    ];

    $form['sno'] = [
      '#type' => 'select',
      '#title' => $this->t('Система налогообложения'),
      '#options' => [
        'none' => 'Не выбрано',
        'osn' => 'ОСН',
        'usn_income' => 'Упрощенная СН (доходы)',
        'usn_income_outcome' => 'Упрощенная СН (доходы минус расходы)',
        'envd' => 'Единый налог на вмененный доход',
        'esn' => 'Единый сельскохозяйственный налог',
        'patent' => 'Патентная СН',
      ],
      '#default_value' => $this->configuration['sno'],
      '#required' => TRUE,
    ];

    $form['payment_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Признак способа расчёта'),
      '#options' => [
        'none' => 'Не выбрано',
        'full_prepayment' => 'Предоплата 100%',
        'prepayment ' => 'Предоплата',
        'advance' => 'Аванс',
        'full_payment' => 'Полный расчёт',
        'partial_payment' => 'Частичный расчёт и кредит',
        'credit' => 'Передача в кредит',
      ],
      '#default_value' => $this->configuration['payment_method'],
      '#required' => TRUE,
    ];

    $form['payment_object'] = [
      '#type' => 'select',
      '#title' => $this->t('Признак предмета расчёта'),
      '#options' => [
        'none' => 'Не выбрано',
        'commodity' => 'Товар',
        'excise' => 'Подакцизный товар',
        'job' => 'Работа',
        'service' => 'Услуга',
        'payment' => 'Платёж',
      ],
      '#default_value' => $this->configuration['payment_object'],
      '#required' => TRUE,
    ];

    $form['tax'] = [
      '#type' => 'select',
      '#title' => $this->t('Налоговая ставка'),
      '#options' => [
        'none' => 'Без НДС',
        'vat0' => 'НДС по ставке 0%',
        'vat10' => 'НДС по ставке 10%',
        'vat110' => 'НДС чека по расчетной ставке 10/110',
        'vat20' => 'НДС чека по ставке 20%',
        'vat120' => 'НДС чека по расчетной ставке 20/120',
        'vat12' => 'НДС по ставке 12% для клиентов из Казахстана',
      ],
      '#default_value' => $this->configuration['tax'],
      '#required' => TRUE,
    ];

//    $form['allowed_currencies'] = [
//      '#type' => 'checkboxes',
//      '#title' => $this->t('Currencies'),
//      '#options' => $this->paymentMethodsList(),
//      '#default_value' => $this->configuration['allowed_currencies'],
//    ];

    $form['logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Logging'),
      '#default_value' => $this->configuration['logging'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['MrchLogin'] = $values['MrchLogin'];
      if (!empty($values['pass1'])) {
        $this->configuration['pass1'] = $values['pass1'];
      }
      if (!empty($values['pass2'])) {
        $this->configuration['pass2'] = $values['pass2'];
      }
      $this->configuration['request_url'] = $values['request_url'];
      $this->configuration['request_url_test'] = $values['request_url_test'];
      $this->configuration['hash_type'] = $values['hash_type'];
      $this->configuration['sno'] = $values['sno'];
      $this->configuration['payment_method'] = $values['payment_method'];
      $this->configuration['payment_object'] = $values['payment_object'];
      $this->configuration['tax'] = $values['tax'];
      $this->configuration['country'] = $values['country'];
      $this->configuration['allowed_currencies'] = $values['allowed_currencies'];
      $this->configuration['logging'] = $values['logging'];
    }
  }

//  function paymentMethodsList() {
//    $url = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/GetCurrencies';
//    $data = [
//      'MerchantLogin' => $this->configuration['MrchLogin'],
//      'Language' => $this->languageManager->getCurrentLanguage()->getId() == 'ru' ? 'ru' : 'en',
//    ];
//    $response = $this->httpClient->get($url, ['query' => $data]);
//
//    $xmlstring = $response->getBody()->getContents();
//    $xml = simplexml_load_string($xmlstring, "SimpleXMLElement", LIBXML_NOCDATA);
//    $json = json_encode($xml);
//    $array = json_decode($json,TRUE);
//    $ret = [];
//
//    if (!isset($array['Groups'])) {
//      return $ret;
//    }
//
//    foreach($array['Groups'] as $groups) {
//      foreach($groups as $group) {
//        foreach($group['Items'] as $item) {
//          if (isset($item['@attributes'])) {
//            $item = array($item);
//          }
//          foreach($item as $currency) {
//            $ret[$currency['@attributes']['Label']] = $currency['@attributes']['Name'];
//          }
//        }
//      }
//    }
//
//    return $ret;
//  }
  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request)
  {
    /** @var PaymentInterface $payment */
    $payment = $this->doValidatePost($request);

    if (!$payment) {
      return FALSE;
    }

    $data = $request->request->all();
    $payment->setState('completed');
    $payment->save();


    echo 'OK' . $data['InvId'];
  }

  protected function doCancel(PaymentInterface $payment, array $status_response)
  {
    $payment->setState('authorization_expired');
    $payment->save();

    return TRUE;
  }

  /**
   * Helper to validate robokassa $_POST data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $data
   *   $_POST to be validated.
   * @param bool $is_interaction
   *   Fallback call flag.
   *
   * @return bool|mixed
   *   Transaction according to POST data or due.
   */
  public function doValidatePost(Request $request, $is_interaction = TRUE)
  {
    $data = $request->request->all();

    // Exit now if the $_POST was empty.
    if (empty($data)) {
      $this->logger->warning('Interaction URL accessed with no POST data submitted.');

      return FALSE;
    }

    // Exit now if any required keys are not exists in $_POST.
    $required_keys = array('OutSum', 'InvId');
    if ($is_interaction) {
      $required_keys[] = 'SignatureValue';
    }
    $unavailable_required_keys = array_diff_key(array_flip($required_keys), $data);

    if (!empty($unavailable_required_keys)) {
      $this->logger->warning('Missing POST keys. POST data: <pre>!data</pre>', array('!data' => print_r($unavailable_required_keys, TRUE)));
      return FALSE;
    }

    // Exit now if missing Checkout ID.
    if (empty($this->configuration['MrchLogin'])) {
      $info = array(
        '!settings' => print_r($this->configuration, 1),
        '!data' => print_r($data, TRUE),
      );
      $this->logger->warning('Missing merchant ID.  POST data: <pre>!data</pre> <pre>!settings</pre>',
        $info);
      return FALSE;
    }

    if ($is_interaction) {
      if ($this->configuration) {
        // Robokassa Signature.
        $robo_sign = $data['SignatureValue'];

        // Create own Signature.
        $signature_data = array(
          $data['OutSum'],
          $data['InvId'],
          $this->configuration['pass2'],
          'shp_label=' . "drupal_official",
        );

        $sign = hash($this->configuration['hash_type'], implode(':', $signature_data));

        // Exit now if missing Signature.
        if (Unicode::strtoupper($robo_sign) != Unicode::strtoupper($sign)) {
          $this->logger->warning('Missing Signature. 1 POST data: !data', array('!data' => print_r($data, TRUE)));
          return FALSE;
        }
      }
    }

    try {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = $this->entityTypeManager->getStorage('commerce_payment')
        ->load($data['InvId']);
    } catch (InvalidPluginDefinitionException $e) {
      $this->logger->warning('Missing transaction id.  POST data: !data', array('!data' => print_r($data, TRUE)));
      return FALSE;
    }

    $amount = new Price($data['OutSum'], 'RUB');

    if (!$payment instanceof PaymentInterface) {
      $this->logger->warning('Missing transaction id.  POST data: !data', array('!data', print_r($data, TRUE)));
      return FALSE;
    }

    if (!$payment->getAmount()->equals($amount)) {
      $this->logger->warning('Missing transaction id amount.  POST data: !data', array('!data' => print_r($data, TRUE)));
      return FALSE;
    }

    return $payment;
  }

  /**
   * Sets transaction 'status' and 'message' depending on RBS status.
   *
   * @param object $transaction
   * @param int $remote_status
   */
  public function setLocalState(PaymentInterface $payment, $remote_status)
  {
    switch ($remote_status) {
      case 'success':
        $payment->setState('completed');
        break;

      case 'fail':
        $payment->setState('authorization_voided');
        break;
    }
  }

}
