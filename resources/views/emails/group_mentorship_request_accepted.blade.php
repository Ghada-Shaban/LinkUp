<!DOCTYPE html>
<html>
<head>
    <title>Group Mentorship Request Accepted</title>
</head>
<body>
    <h1>Group Mentorship Request Accepted</h1>

    <p>Hello {{ $mentorshipRequest->trainee->full_name }},</p>

    <p>Your group mentorship request for <strong>{{ $mentorshipRequest->requestable->title }}</strong> has been accepted by the coach.</p>

    <p>Please proceed to payment to confirm your booking.</p>

    <p>
        <a href="{{ url('/payment/initiate/mentorship_request/' . $mentorshipRequest->id) }}" style="padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;">Proceed to Payment</a>
    </p>

    <p>Thanks,<br>LinkUp Team</p>
</body>
</html>
