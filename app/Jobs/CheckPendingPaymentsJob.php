<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\PendingPayment;
use App\Models\NewSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CheckPendingPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $pendingPayments = PendingPayment::where('payment_due_at', '<', Carbon::now())->get();

        foreach ($pendingPayments as $pendingPayment) {
            $request = $pendingPayment->mentorshipRequest;

            $request->status = 'cancelled';
            $request->save();

            // Delete pending sessions
            NewSession::where('mentorship_request_id', $request->id)
                ->where('status', 'pending')
                ->delete();

            // If GroupMentorship, remove trainee from trainee_ids
            if ($request->requestable_type === \App\Models\GroupMentorship::class) {
                $groupMentorship = $request->requestable;
                $groupMentorship->removeTrainee($request->trainee_id);
            }

            $pendingPayment->delete();

            Log::info('Mentorship request cancelled due to payment timeout', [
                'mentorship_request_id' => $request->id,
            ]);
        }
    }
}
