<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Session Expired</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script>
    (function () {
        var loginUrl = @json($loginUrl ?? url('/login'));

        Swal.fire({
            icon: 'warning',
            title: 'Session Expired',
            text: 'Your page has expired. Please log in again to continue.',
            confirmButtonText: 'Go to Login',
            allowOutsideClick: false,
            allowEscapeKey: false,
            timer: 5000,
            timerProgressBar: true
        }).then(function () {
            window.location.href = loginUrl;
        });
    })();
</script>
</body>
</html>
