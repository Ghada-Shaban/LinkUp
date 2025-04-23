<!DOCTYPE html>
<html>
<head>
    <title>New Mentorship Request</title>
</head>
<body>
    <h1>New Mentorship Request</h1>
    <p><strong>Trainee:</strong> {{ $mentorshipRequest->trainee->user->name }}</p>
    <p><strong>Service:</strong> {{ $mentorshipRequest->service->name }}</p>
    <p><strong>Type:</strong> {{ $mentorshipRequest->type }}</p>
    <p><strong>First Session:</strong> {{ $mentorshipRequest->first_session_time }}</p>
    <p><strong>Duration:</strong> {{ $mentorshipRequest->duration_minutes }} minutes</p>
    <p>Please review the request in your dashboard.</p>
</body>
</html>
