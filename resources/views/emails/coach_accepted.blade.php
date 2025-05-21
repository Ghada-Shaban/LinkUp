<!DOCTYPE html>
<html>
<head>
    <title>Congratulations! Your Coach Registration Has Been Accepted</title>
</head>
<body style="font-family: Arial, sans-serif; text-align: center; padding: 20px;">
    <h1>🎉 Welcome Aboard, {{ $user->full_name }}! 🎉</h1>
    <p>We're thrilled to let you know that your coach registration has been <strong>accepted</strong>! 🚀</p>
    <p>You can now start sharing your expertise and helping others grow. 🌟</p>
    <p>
        <a href="{{ url('/coach/dashboard') }}" style="padding: 10px 20px; background-color: #28a745; color: white; text-decoration: none; border-radius: 5px;">Go to Dashboard 🏠</a>
    </p>
    <p>Let’s make great things happen together! 🤝</p>
    <p>Thanks,<br>{{ config('app.name') }} Team 💼</p>
</body>
</html>
