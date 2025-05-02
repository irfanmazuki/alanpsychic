const auth = localStorage.getItem("admin_auth");
const expiry = parseInt(localStorage.getItem("admin_auth_expiry"), 10);
const now = Date.now();

const isExpired = !expiry || now > expiry;
const isAuthenticated = auth === "granted" && !isExpired;

if (!isAuthenticated) {
  // Clear stale credentials
  localStorage.removeItem("admin_auth");
  localStorage.removeItem("admin_auth_expiry");

  // Redirect to login
  window.location.href = "login.html";
}
