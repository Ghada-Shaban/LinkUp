@component('mail::message')

# Mentorship Request Accepted

Hello {{ $mentorshipRequest->trainee->name }},

Your mentorship request for **{{ $mentorshipRequest->requestable->title }}** has been accepted by the coach.

Please proceed to payment to confirm your booking.

@component('mail::button', ['url' => url('/payment/initiate/mentorship_request/' . $mentorshipRequest->id)])
Proceed to Payment
@endcomponent

Thanks,  
{{ config('app.name') }}

@endcomponent
