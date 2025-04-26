<!DOCTYPE html>
<html>
<head>
    <title>New Mentorship Request</title>
</head>
<body>
    <h1>New Mentorship Request</h1>

    <p>Hello Coach,</p>

    <p>A new mentorship request has been submitted by {{ $mentorshipRequest->trainee->full_name }}.</p>

    <p>
        <strong>Service:</strong> {{ $mentorshipRequest->requestable->title }} <br>
        <strong>Trainee:</strong> {{ $mentorshipRequest->trainee->full_name }} <br>
        <strong>Email:</strong> {{ $mentorshipRequest->trainee->email }}
    </p>

    <p>Please review the request and take action.</p>

    <p>
        <a href="{{ url('/coach/requests') }}" style="padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;">View Requests</a>
    </p>

    <p>Thanks,<br>{{ config('app.name') }}</p>
</body>
</html>
