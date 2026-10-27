<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carevma – Set Your Password</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Roboto, Arial, sans-serif;
            background: linear-gradient(145deg, #f0f4f8 0%, #e6ecf5 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .password-card {
            max-width: 480px;
            width: 100%;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 40px -10px rgba(14, 49, 128, 0.25);
            overflow: hidden;
            transition: transform 0.2s ease;
        }

        .password-card:hover {
            transform: translateY(-4px);
        }

        .card-header {
            background: #ffffff;  /* Deep brand blue */
            padding: 0px 0px ;
            text-align: center;
        }

 
        .company-name span {
            font-size: 40px;
            background: rgba(255,255,255,0.15);
            width: 60px;
            height: 60px;
            border-radius: 60px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .card-header p {
            color: rgba(255,255,255,0.85);
            font-size: 16px;
            font-weight: 400;
        }

        .card-body {
            padding: 30px 32px;
        }

        .welcome-title {
            margin-top: -40px;
            color: #1d21ff;      /* Dark green accent */
            font-size: 26px;
            font-weight: 600;
            margin-bottom: 8px;
            text-align: center;
        }

        .subheading {
            color: #4a5568;
            font-size: 15px;
            text-align: center;
            margin-bottom: 32px;
            border-bottom: 1px solid #eaeef2;
            padding-bottom: 20px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 14px;
            color: #1a2639;
            margin-bottom: 8px;
            letter-spacing: 0.3px;
        }

        .form-control {
            width: 100%;
            padding: 14px 18px;
            font-size: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            transition: all 0.2s;
            background: #f9fcff;
        }

        .form-control:focus {
            outline: none;
            border-color: #0E3180;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(14,49,128,0.1);
        }

        .btn-submit {
            background: #5AE5F3;  /* Fresh light blue */
            color: #0E3180;
            font-weight: 700;
            font-size: 18px;
            padding: 16px 24px;
            border: none;
            border-radius: 40px;
            width: 100%;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 8px 20px -6px rgba(90, 229, 243, 0.5);
            letter-spacing: 0.5px;
            margin-top: 12px;
        }

        .btn-submit:hover {
            background: #4bd2e0;
            transform: scale(1.02);
            box-shadow: 0 12px 28px -8px #0E3180;
        }

        .footer-note {
            margin-top: 32px;
            text-align: center;
            font-size: 13px;
            color: #7b8a9b;
        }

        .footer-note a {
            color: #0E3180;
            text-decoration: none;
            font-weight: 600;
            border-bottom: 1px dotted #0E3180;
        }

        .footer-note a:hover {
            border-bottom: 2px solid #0E3180;
        }

        /* small error/success styling if needed */
        .error-feedback {
            color: #c53030;
            font-size: 13px;
            margin-top: 5px;
        }

        /* hidden token / email fields */
        input[type="hidden"] {
            display: none;
        }
    </style>
</head>
<body>
    <div class="password-card">
        <div class="card-header">
            <div class="company-name">
            <img width="25%" src="http://127.0.0.1:8000/carevma1.jpg" alt="Care VMA">
            </div>
            <p>Secure your account</p>
        </div>

        <div class="card-body">
            <div class="welcome-title">Set your password</div>
            <div class="subheading">Choose a strong password to activate your account</div>

            <form method="POST" action="{{ route('client.password.update') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" name="password" id="password" class="form-control" 
                           placeholder="··········" required>
                    @error('password')
                        <div class="error-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm Password</label>
                    <input type="password" name="password_confirmation" id="password_confirmation" 
                           class="form-control" placeholder="··········" required>
                </div>

                <button type="submit" class="btn-submit">Set Password & Login →</button>
            </form>

     
        </div>
    </div>
</body>
</html>