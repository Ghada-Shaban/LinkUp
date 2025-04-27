<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MentorshipRequest;
use App\Models\NewSession;
use App\Models\PendingPayment;
use App\Models\GroupMentorship;
use App\Models\Service;
use App\Models\MentorshipPlan;
use App\Models\Price; // تأكدي إنك عملتي import للموديل
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    protected $paymobApiKey;
    protected $paymobIntegrationId;
    protected $paymobHmacSecret;

    public function __construct()
    {
        $this->paymobApiKey = env('PAYMOB_API_KEY');
        $this->paymobIntegrationId = env('PAYMOB_INTEGRATION_ID');
        $this->paymobHmacSecret = env('PAYMOB_HMAC_SECRET');
    }

    // دالة لتوليد الـ Token من Paymob
    private function getPaymobToken()
    {
        $response = Http::post('https://accept.paymob.com/api/auth/tokens', [
            'api_key' => $this->paymobApiKey,
        ]);

        if ($response->successful()) {
            return $response->json()['token'];
        }

        throw new \Exception('Failed to get Paymob token: ' . $response->body());
    }

    // دالة لتسجيل Order في Paymob
    private function createPaymobOrder($token, $amount, $merchantOrderId)
    {
        $response = Http::post('https://accept.paymob.com/api/ecommerce/orders', [
            'auth_token' => $token,
            'delivery_needed' => false,
            'amount_cents' => $amount,
            'currency' => 'EGP',
            'merchant_order_id' => $merchantOrderId,
            'items' => [],
        ]);

        if ($response->successful()) {
            return $response->json()['id'];
        }

        throw new \Exception('Failed to create Paymob order: ' . $response->body());
    }

    // دالة لتوليد Payment Key
    private function createPaymentKey($token, $orderId, $amount, $userData)
    {
        $response = Http::post('https://accept.paymob.com/api/acceptance/payment_keys', [
            'auth_token' => $token,
            'amount_cents' => $amount,
            'expiration' => 3600,
            'order_id' => $orderId,
            'billing_data' => [
                'first_name' => $userData['first_name'] ?? 'NA',
                'last_name' => $userData['last_name'] ?? 'NA',
                'email' => $userData['email'] ?? 'test@example.com',
                'phone_number' => $userData['phone_number'] ?? '+923463293642',
                'street' => 'NA',
                'city' => 'NA',
                'country' => 'EG',
                'state' => 'NA',
                'postal_code' => 'NA',
            ],
            'currency' => 'EGP',
            'integration_id' => $this->paymobIntegrationId,
        ]);

        if ($response->successful()) {
            return $response->json()['token'];
        }

        throw new \Exception('Failed to create Payment Key: ' . $response->body());
    }

    public function initiatePayment(Request $request, $type, $id)
    {
        if ($type !== 'mentorship_request') {
            return response()->json(['message' => 'Invalid type'], 400);
        }

        $mentorshipRequest = MentorshipRequest::find($id);
        if (!$mentorshipRequest) {
            return response()->json(['message' => 'Mentorship request not found'], 404);
        }

        if ($mentorshipRequest->status !== 'accepted') {
            return response()->json(['message' => 'Request must be accepted to proceed with payment'], 400);
        }

        $pendingPayment = PendingPayment::where('mentorship_request_id', $mentorshipRequest->id)->first();
        if (!$pendingPayment) {
            return response()->json(['message' => 'No pending payment found for this request'], 404);
        }

        $requestable = $mentorshipRequest->requestable;
        if (!$requestable) {
            return response()->json(['message' => 'Requestable entity not found'], 404);
        }

        $amount = 0;
        $description = '';
        $serviceId = null;

        if ($mentorshipRequest->requestable_type === 'App\\Models\\Service') {
            $service = $requestable;
            $serviceId = $service->service_id;
            $priceEntry = Price::where('service_id', $serviceId)->first();
            if (!$priceEntry) {
                return response()->json(['message' => 'Price not found for this service'], 404);
            }
            $amount = $priceEntry->price * 100;
            $description = "Payment for Service ID: {$requestable->id}";
        } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\GroupMentorship') {
            $service = $requestable->service;
            $serviceId = $service->service_id;
            $priceEntry = Price::where('service_id', $serviceId)->first();
            if (!$priceEntry) {
                return response()->json(['message' => 'Price not found for this group mentorship'], 404);
            }
            $amount = $priceEntry->price * 100;
            $description = "Payment for Group Mentorship ID: {$requestable->id}";
        } elseif ($mentorshipRequest->requestable_type === 'App\\Models\\MentorshipPlan') {
            $service = $requestable->service;
            $serviceId = $service->service_id;
            $priceEntry = Price::where('service_id', $serviceId)->first();
            if (!$priceEntry) {
                return response()->json(['message' => 'Price not found for this mentorship plan'], 404);
            }
            $amount = $priceEntry->price * 100;
            $description = "Payment for Mentorship Plan ID: {$requestable->id}";
        }

        // تسجيل الـ service_id والـ amount في الـ Logs للتأكد
        Log::info('Initiating payment', [
            'mentorship_request_id' => $mentorshipRequest->id,
            'service_id' => $serviceId,
            'price' => $priceEntry->price,
            'amount_in_cents' => $amount,
        ]);

        // التأكد إن الـ amount أكبر من 100 سنت (1 جنيه)
        if ($amount < 100) {
            return response()->json(['message' => 'Amount must be at least 1 EGP (100 cents)'], 400);
        }

        try {
            // 1. توليد الـ Token
            $token = $this->getPaymobToken();

            // 2. تسجيل Order في Paymob
            $merchantOrderId = $mentorshipRequest->id . '-' . time();
            $orderId = $this->createPaymobOrder($token, $amount, $merchantOrderId);

            // 3. توليد Payment Key
            $userData = [
                'first_name' => 'Muhammad',
                'last_name' => 'Hammad',
                'email' => 'mhammadt293@gmail.com',
                'phone_number' => '+923463293642',
            ];
            $paymentKey = $this->createPaymentKey($token, $orderId, $amount, $userData);

            // 4. إرجاع الـ Payment Key والـ Order ID للـ Frontend
            return response()->json([
                'message' => 'Payment key generated successfully',
                'payment_key' => $paymentKey,
                'order_id' => $orderId,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Payment initiation failed', [
                'error' => $e->getMessage(),
                'mentorship_request_id' => $mentorshipRequest->id,
            ]);
            return response()->json(['message' => 'Payment initiation failed: ' . $e->getMessage()], 500);
        }
    }

    // دالة الـ Callback (موجودة من الرد اللي فات)
    public function paymentCallback(Request $request)
    {
        $data = $request->all();
        Log::info('Paymob Callback received', $data);

        $hmac = $request->input('hmac');
        $concatenatedString = implode('', [
            $data['obj']['id'],
            $data['obj']['amount_cents'],
            $data['obj']['created_at'],
            $data['obj']['currency'],
            $data['obj']['error_occured'],
            $data['obj']['has_parent_transaction'],
            $data['obj']['integration_id'],
            $data['obj']['is_3d_secure'],
            $data['obj']['is_auth'],
            $data['obj']['is_capture'],
            $data['obj']['is_refunded'],
            $data['obj']['is_standalone_payment'],
            $data['obj']['is_voided'],
            $data['obj']['order']['id'],
            $data['obj']['owner'],
            $data['obj']['pending'],
            $data['obj']['source_data']['pan'],
            $data['obj']['source_data']['sub_type'],
            $data['obj']['source_data']['type'],
            $data['obj']['success'],
        ]);
        $calculatedHmac = hash_hmac('sha512', $concatenatedString, $this->paymobHmacSecret);

        if ($hmac !== $calculatedHmac) {
            Log::error('Invalid HMAC signature');
            return response()->json(['message' => 'Invalid HMAC signature'], 400);
        }

        if ($data['obj']['success'] === true) {
            $mentorshipRequestId = $data['obj']['order']['merchant_order_id'];
            $mentorshipRequestId = explode('-', $mentorshipRequestId)[0];

            $mentorshipRequest = MentorshipRequest::find($mentorshipRequestId);
            if ($mentorshipRequest) {
                \App\Models\Payment::create([
                    'amount' => $data['obj']['amount_cents'],
                    'payment_method' => $data['obj']['source_data']['type'],
                    'payment_status' => 'success',
                    'date_time' => Carbon::parse($data['obj']['created_at']),
                ]);

                $pendingPayment = PendingPayment::where('mentorship_request_id', $mentorshipRequest->id)->first();
                if ($pendingPayment) {
                    $pendingPayment->delete();
                }

                if ($mentorshipRequest->requestable_type === 'App\\Models\\GroupMentorship') {
                    $groupMentorship = $mentorshipRequest->requestable;
                    $startDateTime = Carbon::parse($groupMentorship->day . ' ' . $groupMentorship->start_time);
                    if ($startDateTime->lt(Carbon::now())) {
                        $startDateTime->addWeek();
                    }

                    NewSession::create([
                        'mentorship_request_id' => $mentorshipRequest->id,
                        'trainee_id' => $mentorshipRequest->trainee_id,
                        'coach_id' => $mentorshipRequest->coach_id,
                        'session_time' => $startDateTime,
                        'duration' => $groupMentorship->duration_minutes,
                        'status' => 'upcoming',
                    ]);
                }
            }
        } else {
            \App\Models\Payment::create([
                'amount' => $data['obj']['amount_cents'],
                'payment_method' => $data['obj']['source_data']['type'],
                'payment_status' => 'failed',
                'date_time' => Carbon::parse($data['obj']['created_at']),
            ]);
        }

        return response()->json(['message' => 'Callback processed'], 200);
    }
}
