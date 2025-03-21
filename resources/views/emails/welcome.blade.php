<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to LinkUp!</title>
</head>
<body>
    <h2>Welcome, {{ $name }}!</h2>
    
    @if ($role === 'Coach')
        <p>Thank you for signing up as a Coach. Your account is currently under review. We will notify you once it has been approved.</p>
    @else
        <p>Thank you for joining LinkUp! You can now start exploring and booking coaching sessions.</p>
    @endif

    <p>Best Regards,</p>
    <p>LinkUp Team</p>
</body>
</html>