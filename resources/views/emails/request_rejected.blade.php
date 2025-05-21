<!DOCTYPE html>
<html>
<head>
    <title>Mentorship Request Rejected</title>
</head>
<body>
    <h1>Mentorship Request Rejected</h1>

    <p>Hello {{ $mentorshipRequest->trainee->full_name }},</p>

    <p>Unfortunately, your mentorship request for <strong>{{ $mentorshipRequest->requestable->title }}</strong> has been rejected by the coach.</p>

    <p>You can try submitting another request or contact the coach for more details.</p>

    <p>
        <a href="{{ url('/trainee/mentorship-requests') }}" style="padding: 10px 20px; background-color: #dc3545; color: white; text-decoration: none; border-radius: 5px;">Submit Another Request</a>
    </p>

    <p>Thanks,<br>{{ config('app.name') }} Team</p>
</body>
</html>
