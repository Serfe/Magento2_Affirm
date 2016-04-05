<?php
namespace OnePica\Affirm\Model;

use \Magento\Quote\Api\CartManagementInterface;
use \Magento\Checkout\Model\Session;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\ResourceModel\Report\Order;

/**
 * Class Checkout for Affirm
 * This class is a wrapper for the Affirm checkout process
 *
 * @package OnePica\Affirm\Model
 */
class Checkout
{
    /**
     * Checkout session object
     *
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * Quote management object
     *
     * @var \Magento\Quote\Api\CartManagementInterfaces
     */
    protected $quoteManagement;

    /**
     * Current checkout quote
     *
     * @var \Magento\Quote\Model\Quote
     */
    protected $quote;

    /**
     * Customer session object
     *
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * Checkout helper data
     *
     * @var \Magento\Checkout\Helper\Data
     */
    protected $checkoutData;

    /**
     * Magento order instance
     *
     * @var \Magento\Sales\Model\Order
     */
    protected $order;

    /**
     * Order sender object
     *
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    /**
     * Inject checkout init objects
     *
     * @param CartManagementInterface         $cartManagement
     * @param Session                         $checkoutSession
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Helper\Data   $checkoutData
     * @param OrderSender                     $orderSender
     */
    public function __construct(
        CartManagementInterface $cartManagement,
        Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Helper\Data $checkoutData,
        OrderSender $orderSender
    )
    {
        $this->checkoutSession = $checkoutSession;
        $this->quoteManagement = $cartManagement;
        $this->quote = $this->checkoutSession->getQuote();
        $this->customerSession = $customerSession;
        $this->checkoutData = $checkoutData;
        $this->orderSender = $orderSender;
    }

    /**
     * Place order based on prepared quote
     */
    public function place()
    {
        if (!$this->quote->getGrandTotal()) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __(
                    'Affirm can\'t process orders with a zero balance due. '
                    . 'To finish your purchase, please go through the standard checkout process.'
                )
            );
        }
        if ($this->getCheckoutMethod() == \Magento\Checkout\Model\Type\Onepage::METHOD_GUEST) {
            $this->prepareGuestQuote();
        }
        $this->quote->collectTotals();
        $this->ignoreAddressValidation();
        $this->order = $this->quoteManagement->submit($this->quote);

        switch ($this->order->getState()) {
            // even after placement paypal can disallow to authorize/capture, but will wait until bank transfers money
            case \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT:
                // TODO
                break;
            // regular placement, when everything is ok
            case \Magento\Sales\Model\Order::STATE_PROCESSING:
            case \Magento\Sales\Model\Order::STATE_COMPLETE:
            case \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW:
                $this->orderSender->send(($this->order));
                $this->checkoutSession->start();
                break;
            default:
                break;
        }
    }

    /**
     * Retrieve customer session object
     *
     * @return \Magento\Customer\Model\Session
     */
    protected function getCustomerSession()
    {
        return $this->customerSession;
    }

    /**
     * Get method checkout
     *
     * @return string
     */
    protected function getCheckoutMethod()
    {
        if ($this->getCustomerSession()->isLoggedIn()) {
            return \Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER;
        }
        if (!$this->quote->getCheckoutMethod()) {
            if ($this->checkoutData->isAllowedGuestCheckout($this->quote)) {
                $this->quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST);
            } else {
                $this->quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_REGISTER);
            }
        }
        return $this->quote->getCheckoutMethod();
    }

    /**
     * Prepare quote for guest checkout order submit
     *
     * @return $this
     */
    protected function prepareGuestQuote()
    {
        $quote = $this->quote;
        $quote->setCustomerId(null)
            ->setCustomerEmail($quote->getBillingAddress()->getEmail())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        return $this;
    }

    /**
     * Make sure addresses will be saved without validation errors
     *
     * @return void
     */
    protected function ignoreAddressValidation()
    {
        $this->quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$this->quote->getIsVirtual()) {
            $this->quote->getShippingAddress()->setShouldIgnoreValidation(true);
            if (!$this->quote->getValue('requireBillingAddress')
                && !$this->quote->getBillingAddress()->getEmail()
            ) {
                $this->quote->getBillingAddress()->setSameAsBilling(1);
            }
        }
    }

    /**
     * Retrieve order instance
     *
     * @return mixed
     */
    public function getOrder()
    {
        return $this->order;
    }
}
