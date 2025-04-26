@component('mail::message')

# New Mentorship Request

Hello Coach,

A new mentorship request has been submitted by {{ $mentorshipRequest->trainee->name }}.

**Service:** {{ $mentorshipRequest->requestable->title }}  
**Trainee:** {{ $mentorshipRequest->trainee->name }}  
**Email:** {{ $mentorshipRequest->trainee->email }}

Please review the request and take action.

@component('mail::button', ['url' => url('/coach/requests')])
View Requests
@endcomponent

Thanks,  
{{ config('app.name') }}

@endcomponent
