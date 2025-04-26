@component('mail::message')

# Session Booked Successfully

Hello,

Your session has been booked successfully!

@if(isset($entity->mentorship_request_id))
    **Service:** {{ $entity->requestable->title }}  
    @if($entity->requestable_type === 'App\\Models\\MentorshipPlan')
        Please schedule your sessions at your convenience.
        @component('mail::button', ['url' => url('/mentorship-requests/' . $entity->id . '/schedule')])
        Schedule Sessions
        @endcomponent
    @endif
@else
    **Service:** {{ \App\Models\Service::find($entity->service_id)->title ?? 'Service' }}  
    **Session Time:** {{ \Carbon\Carbon::parse($entity->session_time)->format('Y-m-d H:i:s') }}  
    **Duration:** {{ $entity->duration_minutes }} minutes
@endif

Thanks,  
{{ config('app.name') }}

@endcomponent
