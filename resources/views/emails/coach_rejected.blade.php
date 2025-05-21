<!DOCTYPE html>
<html>
<head>
    <title>Sorry, Your Coach Registration Has Been Rejected</title>
</head>
<body style="font-family: Arial, sans-serif; text-align: center; padding: 20px;">
    <h1>😔 We’re Sorry, {{ $user->full_name }} 😔</h1>
    <p>Unfortunately, your coach registration has been <strong>rejected</strong>. 💔</p>
    <p>Don’t worry, you can try registering again or reach out to us for more details. 📧</p>
    <p>
        <a href="{{ url('/coach/register') }}" style="padding: 10px 20px; background-color: #dc3545; color: white; text-decoration: none; border-radius: 5px;">Try Again 🔄</a>
    </p>
    <p>We’re here to help if you need us! 🖐️</p>
    <p>Thanks,<br>{{ config('app.name') }} Team 💼</p>
</body>
</html>
