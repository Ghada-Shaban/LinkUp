<!DOCTYPE html>
<html>
<head>
    <title>Mentorship Request Accepted</title>
</head>
<body>
    <h1>Mentorship Request Accepted!</h1>
    <p>Dear Trainee,</p>
    <p>We are pleased to inform you that your mentorship request for "<strong>{{ $title }}</strong>" (Type: {{ $type }}) has been accepted.</p>
    
    @if($type === 'Plan')
        <h2>Scheduled Sessions:</h2>
        <ul>
            @foreach($plan_schedule as $session)
                <li>{{ \Carbon\Carbon::parse($session)->format('F j, Y, g:i A') }}</li>
            @endforeach
        </ul>
    @else
        <p><strong>Session Time:</strong> {{ \Carbon\Carbon::parse($session_time)->format('F j, Y, g:i A') }}</p>
    @endif

    <p>You can view the details in your upcoming sessions.</p>
    <p>Best regards,<br>Your Mentorship Platform</p>
</body>
</html>
