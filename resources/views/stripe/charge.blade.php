<html lang="en">
    <head>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <script type="text/javascript" src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
            
        <style>
            .mb-10 {
                margin-bottom: 10px;
            }
            .mb-40 {
                margin-bottom: 40px;
            }
        </style>
    </head>
    
    <body>
    
        <div class="row">
            <div class="col-md-2"></div>
            <div class="col-md-8 text-center" style="margin-top: 30vh">
                <div class="mb-40">
                    <img src="{{ url('images/loading.gif') }}" />
                </div>
                <h1 class="text-semibold mb-10">{!! trans('messages.subscription.checkout.processing_payment') !!}</h1>
        
                <div class="sub-section">
                    <div class="row">
                        <div class="col-md-12">
                            
                        
                            <p class="text-muted">{!! trans('messages.subscription.checkout.processing_payment.intro') !!}</p>
                            
                            <form id="pay_now" method="POST" action="{{ action('\Acelle\Cashier\Controllers\StripeController@charge', [
                                'subscription_id' => $subscription->uid,
                            ]) }}">
                                {{ csrf_field() }}
                            </form>
                            
                            <a id="pay_now" style="display: none" link-method="POST"
                                class="btn btn-mc_primary"
                                href="{{ action('\Acelle\Cashier\Controllers\StripeController@charge', ['subscription_id' => $subscription->uid]) }}">
                                {{ trans('messages.payment.pay_now') }}
                            </a>
        
                            <script>
                                setTimeout(function() {
                                    $('#pay_now').submit();
                                }, 2000);
                            </script>
                        </div>
                    </div>
        
                </div>
            </div>
        </div>

    </body>
</html>
