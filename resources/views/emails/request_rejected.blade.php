@component('mail::message')

# Mentorship Request Rejected

Hello {{ $mentorshipRequest->trainee->name }},

Unfortunately, your mentorship request for **{{ $mentorshipRequest->requestable->title }}** has been rejected by the coach.

You can try submitting another request or contact the coach for more details.

Thanks,  
{{ config('app.name') }}

@endcomponent
