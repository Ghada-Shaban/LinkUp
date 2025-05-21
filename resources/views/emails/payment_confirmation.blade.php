<!DOCTYPE html>
<html>
<head>
    <title>Payment Confirmed & Session Schedule</title>
</head>
<body style="font-family: Arial, sans-serif; text-align: center; padding: 20px;">
    <h1>✅ Payment Confirmed, {{ $mentorshipRequest->trainee->full_name }}! ✅</h1>
    <p>Thank you for your payment of ${{ $mentorshipRequest->payment->amount }}. Your session(s) have been successfully scheduled! 🎉</p>
    <p>Below are the details of your upcoming sessions:</p>
    <table style="margin: 0 auto; border-collapse: collapse; width: 80%;">
        <tr style="background-color: #f2f2f2;">
            <th style="padding: 10px; border: 1px solid #ddd;">Session #</th>
            <th style="padding: 10px; border: 1px solid #ddd;">Date & Time (UTC)</th>
        </tr>
        @foreach ($sessions as $index => $session)
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd;">{{ $index + 1 }}</td>
                <td style="padding: 10px; border: 1px solid #ddd;">{{ $session->date_time->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</td>
            </tr>
        @endforeach
    </table>
    <p>
        <a href="{{ url('/trainee/upcoming-sessions') }}" style="padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;">View Upcoming Sessions 📅</a>
    </p>
    <p>If you have any questions, feel free to contact us! 📧</p>
    <p>Thanks,<br>{{ config('app.name') }} Team 💼</p>
</body>
</html>
