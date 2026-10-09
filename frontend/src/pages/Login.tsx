import { useState, type FormEvent } from "react";
import "../css/Login_form.css";
import { getSupabaseClient } from "../lib/supabase";
import { apiRequest } from "../lib/api";

type LoginRole = "user" | "staff";

interface LoginProps {
  onClose?: () => void;
}

function Login({ onClose }: LoginProps) {
  const [role, setRole] = useState<LoginRole>("user");
  const [signupMode, setSignupMode] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");

  function changeRole(nextRole: LoginRole) {
    setRole(nextRole);
    setSignupMode(false);
    setMessage("");
    setPassword("");
    setConfirmPassword("");
    setShowPassword(false);
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");

    if (signupMode && password !== confirmPassword) {
      setMessage("Passwords do not match.");
      return;
    }

    if (signupMode && password.length < 8) {
      setMessage("Password must be at least 8 characters.");
      return;
    }

    setLoading(true);

    try {
      if (signupMode) {
        const result = await apiRequest<{ message: string; session: { access_token: string; refresh_token: string } | null }>("/api/auth/signup", {
          name: name.trim(),
          email,
          password,
        });

        if (result.session) {
          const { error } = await getSupabaseClient().auth.setSession(result.session);
          if (error) throw error;
        }

        setMessage(result.message);
        setSignupMode(false);
        setPassword("");
        setConfirmPassword("");
        return;
      }

      const result = await apiRequest<{
        session: { access_token: string; refresh_token: string };
        account: { role: string };
      }>("/api/auth/login", { email, password });
      const actualRole = result.account.role.trim().toLowerCase();

      if ((role === "staff" && actualRole !== "staff") || (role === "user" && actualRole !== "user")) {
        throw new Error("Your account does not match the selected account type.");
      }

      const { error } = await getSupabaseClient().auth.setSession(result.session);
      if (error) throw error;

      setMessage("Login successful.");
      window.location.assign(actualRole === "staff" ? "/staff/dashboard" : "/user/dashboard");
    } catch (error: unknown) {
      setMessage(error instanceof Error ? error.message : "Unable to connect. Please try again.");
    } finally {
      setLoading(false);
    }
  }
  return (
    <div className="modal-overlay">
      <section
        className="login-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="login-title"
        data-role={role}
      >
        <div className="modal-top">
          <h2 id="login-title">
            {signupMode
              ? "Create User account"
              : role === "staff"
                ? "Administrator Login"
                : "User Login"}
          </h2>

          {onClose && (
            <button
              type="button"
              className="close-modal"
              onClick={onClose}
              aria-label="Close login"
            >
              ×
            </button>
          )}
        </div>

        <p className="modal-subtitle">
          {signupMode
            ? "Create your account using your email and password."
            : `Sign in with your ${
                role === "staff" ? "administrator" : "user"
              } account.`}
        </p>

        {!signupMode && (
          <div className="role-picker" role="group" aria-label="Account type">
            <button
              type="button"
              aria-pressed={role === "user"}
              onClick={() => changeRole("user")}
            >
              User
            </button>

            <button
              type="button"
              aria-pressed={role === "staff"}
              onClick={() => changeRole("staff")}
            >
              Administrator
            </button>
          </div>
        )}

        <form onSubmit={handleSubmit}>
          {signupMode && (
            <div className="login-field">
              <label htmlFor="signup-name">Full name</label>
              <input
                id="signup-name"
                type="text"
                autoComplete="name"
                maxLength={100}
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="Enter your full name"
                required
              />
            </div>
          )}

          <div className="login-field">
            <label htmlFor="login-email">Email address</label>
            <input
              id="login-email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="you@example.com"
              required
            />
          </div>

          <div className="login-field">
            <label htmlFor="login-password">Password</label>

            <div className="password-field">
              <input
                id="login-password"
                type={showPassword ? "text" : "password"}
                autoComplete={
                  signupMode ? "new-password" : "current-password"
                }
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                minLength={signupMode ? 8 : undefined}
                placeholder="Enter your password"
                required
              />

              <button
                type="button"
                className="password-toggle"
                onClick={() => setShowPassword((value) => !value)}
                aria-pressed={showPassword}
              >
                {showPassword ? "Hide" : "Show"}
              </button>
            </div>
          </div>

          {signupMode && (
            <div className="login-field">
              <label htmlFor="confirm-password">
                Confirm password
              </label>
              <input
                id="confirm-password"
                type={showPassword ? "text" : "password"}
                autoComplete="new-password"
                value={confirmPassword}
                onChange={(event) =>
                  setConfirmPassword(event.target.value)
                }
                placeholder="Confirm your password"
                required
              />
            </div>
          )}

          <button
            className="button login-submit"
            type="submit"
            disabled={loading}
          >
            {loading
              ? "Please wait..."
              : signupMode
                ? "Create User account"
                : `Sign in as ${
                    role === "staff" ? "Administrator" : "User"
                  }`}
          </button>

          <p className="login-message" role="status" aria-live="polite">
            {message}
          </p>

          {role === "user" && (
            <button
              type="button"
              className="button secondary login-submit"
              disabled={loading}
              onClick={() => {
                setSignupMode((value) => !value);
                setMessage("");
                setPassword("");
                setConfirmPassword("");
              }}
            >
              {signupMode ? "Back to sign in" : "Create User account"}
            </button>
          )}

          {!signupMode && (
            <p className="login-help">
              New here? Create a User account to get started.
            </p>
          )}
        </form>
      </section>
    </div>
  );
}

export default Login;
