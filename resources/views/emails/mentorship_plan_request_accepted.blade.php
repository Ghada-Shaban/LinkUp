<!DOCTYPE html>
<html>
<head>
    <title>Mentorship Plan Request Accepted</title>
</head>
<body>
    <h1>Mentorship Plan Request Accepted</h1>

    <p>Hello {{ $mentorshipRequest->trainee->full_name }},</p>

    <p>Your mentorship plan request for <strong>{{ $mentorshipRequest->requestable->title }}</strong> has been accepted by the coach.</p>

    <p>Please proceed to book your sessions before making the payment.</p>

    <p>
        <a href="{{ url('/api/coach/' . $mentorshipRequest->coach_id . '/book') }}" style="padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;">Book Sessions</a>
    </p>

    <p>Thanks,<br>LinkUp Team</p>
</body>
</html>
