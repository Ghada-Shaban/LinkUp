@component('mail::message')
# Mentorship Request Rejected

Hello {{ $mentorshipRequest->trainee->full_name }},

Unfortunately, your mentorship request for **{{ $mentorshipRequest->requestable->title }}** has been rejected by the coach.

You can try submitting another request or contact the coach for more details.

@component('mail::button', ['url' => url('/trainee/mentorship-requests')])
Submit Another Request
@endcomponent

Thanks,  
{{ config('app.name') }}

@endcomponent
