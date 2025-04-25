<!DOCTYPE html>
<html>
<head>
    <title>Payment Reminder</title>
</head>
<body>
    <h1>Payment Reminder</h1>
    <p>Dear Trainee,</p>
    <p>Please complete the payment for your mentorship request within 24 hours to confirm your booking.</p>
    <p><strong>Service:</strong> {{ $request->requestable->title }}</p>
    <p><a href="{{ env('FRONTEND_URL') }}/mentorship-requests/{{ $request->id }}/pay">Pay Now</a></p>
    <p>If payment is not completed, your request will be cancelled.</p>
</body>
</html>
