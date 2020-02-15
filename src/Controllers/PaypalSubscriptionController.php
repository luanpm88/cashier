<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

class PaypalSubscriptionController extends Controller
{
    public function __construct()
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return Cashier::getPaymentGateway('paypal_subscription');
    }

    /**
     * Get return url.
     *
     * @return string
     **/
    public function getReturnUrl(Request $request) {
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }

        return $return_url;
    }

    /**
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function checkout(Request $request, $subscription_id)
    {
        $service = $this->getPaymentService();
        // get access token
        $service->getAccessToken();

        $subscription = Subscription::findByUid($subscription_id);
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // if subscription is active
        if ($subscription->isActive()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // get access token
        $accessToken = $service->getAccessToken();
        $paypalPlan = $service->getPaypalPlan($subscription);

        if ($request->isMethod('post')) {
            // create subscription
            $paypalSubscription = $service->createPaypalSubscription($subscription, $request->subscriptionID);

            // add transaction
            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.subscribe_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);

            // set pending
            $subscription->setPending();

            // Redirect to my subscription page
            return redirect()->away($service->getPendingUrl($subscription, $request));
        }
        
        return view('cashier::paypal_subscription.checkout', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $this->getReturnUrl($request),
            'accessToken' => $accessToken,
            'paypalPlan' => $paypalPlan,
        ]);
    }

    /**
     * Subscription pending page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function pending(Request $request, $subscription_id)
    {
        $service = $this->getPaymentService();
        $subscription = Subscription::findByUid($subscription_id);
        $transaction = $service->getInitTransaction($subscription);
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        if (!$subscription->isPending() || !$transaction->isPending()) {
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::paypal_subscription.pending', [
            'service' => $service,
            'subscription' => $subscription,
            'transaction' => $transaction,
            'paypalSubscription' => $subscription->getMetadata()['subscription'],
            'return_url' => $this->getReturnUrl($request),
        ]);
    }

    /**
     * Renew subscription.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function changePlan(Request $request, $subscription_id)
    {
        $request->session()->flash('alert-error', trans('cashier::messages.paypal.not_support_change_plan_yet'));
        return redirect()->away($this->getReturnUrl($request));

        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // @todo dependency injection 
        $plan = \Acelle\Model\Plan::findByUid($request->plan_id);        
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // check if status is not pending
        if ($service->hasPending($subscription)) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // calc plan before change
        try {
            $result = $service->calcChangePlan($subscription, $plan);
        } catch (\Exception $e) {
            $request->session()->flash('alert-error', 'Can not change plan: ' . $e->getMessage());
            return redirect()->away($this->getReturnUrl($request));
        }

        // get access token
        $accessToken = $service->getAccessToken();
        $paypalPlan = $service->createPaypalPlan($subscription, $plan, $result['remain_amount']);

        if ($request->isMethod('post')) {
            // // create subscription
            // $paypalSubscription = $service->createPaypalSubscription($subscription, $request->subscriptionID);

            // // add log
            // $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE, [
            //     'old_plan' => $subscription->plan->getBillableName(),
            //     'plan' => $plan->getBillableName(),
            //     'price' => $plan->getBillableFormattedPrice(),
            // ]);

            // // add transaction
            // $subscription->addTransaction(SubscriptionTransaction::TYPE_PLAN_CHANGE, [
            //     'ends_at' => $subscription->ends_at,
            //     'current_period_ends_at' => $subscription->current_period_ends_at,
            //     'status' => SubscriptionTransaction::STATUS_PENDING,
            //     'title' => trans('cashier::messages.transaction.change_plan', [
            //         'old_plan' => $subscription->plan->getBillableName(),
            //         'plan' => $subscription->plan->getBillableName(),
            //     ]),
            //     'amount' => $result['amount'],
            // ]);

            // // Redirect to my subscription page
            // return redirect()->away($service->getChangePlanPendingUrl($subscription, $request));
        }
        
        return view('cashier::paypal_subscription.change_plan', [
            'service' => $service,
            'subscription' => $subscription,
            'newPlan' => $plan,
            'return_url' => $this->getReturnUrl($request),
            'nextPeriodDay' => $result['ends_at'],
            'newAmount' => $result['new_amount'],
            'remainAmount' => $result['remain_amount'],
            'amount' => $result['amount'],
            'accessToken' => $accessToken,
            'paypalPlan' => $paypalPlan,
        ]);
    }

    /**
     * Renew subscription.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function renew(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // check if status is not pending
        if ($service->hasPending($subscription)) {
            return redirect()->away($request->return_url);
        }

        // return url
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }
        
        if ($request->isMethod('post')) {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEW, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            if ($subscription->plan->price > 0) {
                // check order ID
                $service->checkOrderId($request->orderID);
                
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
            }
            
            // renew
            $subscription->renew();

            // subscribe to plan
            $subscription->addTransaction(SubscriptionTransaction::TYPE_RENEW, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'title' => trans('cashier::messages.transaction.renew_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice(),
            ]);
            
            sleep(1);
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEWED, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::paypal_subscription.renew', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $request->return_url,
        ]);
    }

    /**
     * Payment redirecting.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function paymentRedirect(Request $request)
    {
        return view('cashier::paypal_subscription.payment_redirect', [
            'redirect' => $request->redirect,
        ]);
    }
}