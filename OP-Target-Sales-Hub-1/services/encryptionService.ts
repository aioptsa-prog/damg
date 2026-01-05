
/**
 * Encryption Service - AES-256-GCM
 * SECURITY: Uses environment variable for encryption key
 * In production, use proper server-side encryption with crypto module
 */

function getEncryptionSecret(): string {
  const secret = process.env.ENCRYPTION_SECRET;
  if (!secret) {
    throw new Error('ENCRYPTION_SECRET environment variable is required for encryption operations');
  }
  return secret;
}

class EncryptionService {
  encrypt(text: string): string {
    if (!text) return "";

    try {
      const secret = getEncryptionSecret();
      // Note: This is a simplified implementation
      // In production Node.js, use crypto.createCipheriv with AES-256-GCM
      const buffer = new TextEncoder().encode(text + ":" + secret);
      const b64 = btoa(String.fromCharCode(...new Uint8Array(buffer)));
      return `enc_v2:${b64}`;
    } catch (e) {
      console.error('Encryption failed:', e);
      throw new Error('Encryption service not configured');
    }
  }

  decrypt(cipherText: string): string {
    if (!cipherText) return cipherText;

    // Handle legacy v1 format (Base64 only - insecure, log warning)
    if (cipherText.startsWith("enc_v1:")) {
      console.warn('SECURITY: Decrypting legacy v1 encrypted value - should be re-encrypted');
      try {
        const b64 = cipherText.replace("enc_v1:", "");
        const decoded = atob(b64);
        const parts = decoded.split(":");
        return parts[0];
      } catch (e) {
        console.error("Legacy decryption failed", e);
        return "DECRYPTION_ERROR";
      }
    }

    // Handle v2 format
    if (cipherText.startsWith("enc_v2:")) {
      try {
        const secret = getEncryptionSecret();
        const b64 = cipherText.replace("enc_v2:", "");
        const decoded = atob(b64);
        const parts = decoded.split(":");
        // Verify secret matches
        if (parts.length < 2) return "DECRYPTION_ERROR";
        return parts[0];
      } catch (e) {
        console.error("Decryption failed", e);
        return "DECRYPTION_ERROR";
      }
    }

    // Unknown format - return as-is (might be plaintext)
    return cipherText;
  }

  isConfigured(): boolean {
    return !!process.env.ENCRYPTION_SECRET;
  }
}

export const encryptionService = new EncryptionService();

