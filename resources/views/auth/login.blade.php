@extends('layouts.auth')

@section('title', 'Login')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6 col-xl-5">
            <div class="card overflow-hidden">
                <div class="bg-primary bg-soft">
                    <div class="row">
                        <div class="col-7">
                            <div class="text-primary p-4">
                                <h5 class="text-primary">Welcome Back!</h5>
                                <p>Sign in to Ecom360 Analytics Platform.</p>
                            </div>
                        </div>
                        <div class="col-5 align-self-end">
                            <img src="{{ URL::asset('build/images/profile-img.png') }}" alt="" class="img-fluid">
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div class="auth-logo">
                        <a href="/" class="auth-logo-light">
                            <div class="avatar-md profile-user-wid mb-4">
                                <span class="avatar-title rounded-circle bg-light">
                                    <i class="bx bx-analyse text-primary" style="font-size:24px"></i>
                                </span>
                            </div>
                        </a>
                        <a href="/" class="auth-logo-dark">
                            <div class="avatar-md profile-user-wid mb-4">
                                <span class="avatar-title rounded-circle bg-light">
                                    <i class="bx bx-analyse text-primary" style="font-size:24px"></i>
                                </span>
                            </div>
                        </a>
                    </div>
                    <div class="p-2">
                        <form method="POST" action="{{ route('login') }}">
                            @csrf
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control @error('email') is-invalid @enderror"
                                       id="email" name="email" value="{{ old('email') }}"
                                       placeholder="Enter email" autofocus required>
                                @error('email')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="password">Password</label>
                                <div class="input-group auth-pass-inputgroup">
                                    <input type="password" class="form-control @error('password') is-invalid @enderror"
                                           id="password" name="password"
                                           placeholder="Enter password" required>
                                    <button class="btn btn-light" type="button" id="password-addon"
                                            onclick="var x=document.getElementById('password');x.type=x.type==='password'?'text':'password';">
                                        <i class="mdi mdi-eye-outline"></i>
                                    </button>
                                    @error('password')
                                        <span class="invalid-feedback" role="alert">
                                            <strong>{{ $message }}</strong>
                                        </span>
                                    @enderror
                                </div>
                            </div>

                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="remember" name="remember"
                                       {{ old('remember') ? 'checked' : '' }}>
                                <label class="form-check-label" for="remember">Remember me</label>
                            </div>

                            <div class="mt-3 d-grid">
                                <button class="btn btn-primary waves-effect waves-light" type="submit">Log In</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="mt-5 text-center">
                <p>&copy; {{ date('Y') }} Ecom360 Analytics Platform</p>
            </div>
        </div>
    </div>
</div>
@endsection
