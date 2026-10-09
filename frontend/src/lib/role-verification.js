let verifiedRole = null;

export function isRoleVerified(role) {
  return verifiedRole === role;
}

export function verifyRole(role) {
  verifiedRole = role;
}

export function clearRoleVerification() {
  verifiedRole = null;
}
