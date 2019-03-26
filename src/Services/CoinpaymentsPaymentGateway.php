<?php
namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Subscription;
use Carbon\Carbon;
use Acelle\Library\CoinPayment\CoinpaymentsAPI;
use Acelle\Cashier\InvoiceParam;

class CoinpaymentsPaymentGateway implements PaymentGatewayInterface
{
    public $coinPaymentsAPI;
    
    // Contruction
    public function __construct($merchantId, $publicKey, $privateKey, $ipnSecret)
    {   
        $this->coinPaymentsAPI = new CoinpaymentsAPI($privateKey, $publicKey, 'json'); // new CoinPayments($privateKey, $publicKey, $merchantId, $ipnSecret, null);
    }
    
    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
    {
        $info = $this->coinPaymentsAPI->getBasicInfo();
        
        if (isset($info["error"]) && $info["error"] != "ok") {
            throw new \Exception($info["error"]);
        }
    }
    
    /**
     * Check if support recurring.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function isSupportRecurring()
    {
        return false;
    }
    
    /**
     * Create a new subscriptionParam.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function charge($subscription)
    {
    }
    
    /**
     * Check if customer has valid card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserHasCard($user)
    {
        return false;
    }
    
    /**
     * Update user card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserUpdateCard($user, $params)
    {
    }
    
    /**
     * Retrieve subscription param.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
     */
    public function retrieveSubscription($subscriptionId)
    {
        $subscription = Subscription::findByUid($subscriptionId);
        
        // Check if plan is free
        if ($subscription->plan->getBillableAmount() == 0) {
            return new SubscriptionParam([
                'status' => Subscription::STATUS_DONE,
                'createdAt' => $subscription->created_at,
            ]);
        }
        
        $metadata = $subscription->getMetadata();
        if (isset($metadata->transaction_id)) {
            $found = $this->coinPaymentsAPI->GetTxInfoSingle($metadata->transaction_id)['result'];
            $transactionId = $metadata->transaction_id;
        } else {        
            $transactions = $this->coinPaymentsAPI->GetTxIds(["limit" => 100]);
            $found = null;
            $transactionId = null;
            foreach($transactions["result"] as $transaction) {
                $result = $this->coinPaymentsAPI->GetTxInfoSingle($transaction, 1)["result"];
                $id = $result["checkout"]["item_number"];
                if ($subscriptionId == $id) {
                    $found = $result;
                    $transactionId = $transaction;
                    break;
                }
            }
        }
        
        if (!isset($found)) {
            throw new \Exception('Subscription can not be found');
        }
        
        // Update subscription id
        $subscription->updateMetadata(['transaction_id' => $transactionId]);
        
        $subscriptionParam = new SubscriptionParam([
            'createdAt' => $found["time_created"],
        ]);
        
        if ($found["status"] == 0) {
            $subscriptionParam->status = Subscription::STATUS_PENDING;
        }
        
        if ($found["status"] > 0) {
            $subscriptionParam->status = Subscription::STATUS_DONE;
        }
        
        // end subscription if transaction is failed
        if ($found["status"] < 0) {
            $subscriptionParam->endsAt = $found["time_expires"];
        }
        
        return $subscriptionParam;
    }
    
    /**
     * Cancel subscription.
     *
     * @param  Subscription  $subscription
     * @return [$currentPeriodEnd]
     */
    public function cancelSubscription($subscriptionId)
    {
        // @already cancel at end of period
    }
    
    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function resumeSubscription($subscriptionId)
    {
    }
    
    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function cancelNowSubscription($subscriptionId)
    {
    }
    
    /**
     * Change subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function changePlan($subscriptionId, $plan)
    {
        $currentSubscription = $user->subscription();
        $currentSubscription->markAsCancelled();
        
        $subscription = $user->createSubscription($plan, $this);        
        $subscription->charge($this);
        
        return $subscription;
    }
    
    /**
     * Get subscription invoices.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getInvoices($subscriptionId)
    {
        $transactions = $this->coinPaymentsAPI->GetTxIds(["limit" => 100]);
        
        $statuses = [
            -2 => 'Refund / Reversal',
            -1 => 'Cancelled / Timed Out',
            0 => 'Waiting',
            1 => 'Coin Confirmed',
            2 => 'Queued',
            3 => 'PayPal Pending',
            100 => 'Complete',
        ];
        
        $invoices = [];
        foreach($transactions["result"] as $transaction) {
            $result = $this->coinPaymentsAPI->GetTxInfoSingle($transaction, 1)["result"];
            $id = $result["checkout"]["item_number"];
            if ($subscriptionId == $id) {
                $invoices[] = new InvoiceParam([
                    'time' => $result['time_created'],
                    'amount' => $result['amount'] . " " . $result['coin'],
                    'description' => $result['status_text'],
                    'status' => $statuses[$result['status']]
                ]);
            }
        }
        
        return $invoices;
    }
    
    /**
     * Top-up subscription.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function topUp($subscription)
    {
        return false;
    }
}