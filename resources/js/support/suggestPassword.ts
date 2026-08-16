/**
 * Builds a mail password an administrator can read to somebody over the phone.
 *
 * Generated in the browser, never on the server. A suggestion produced
 * server-side would travel in the page payload — and would therefore reach the
 * browser's history and developer tools even for the suggestions that are
 * rotated past and never used (docs/features/mailboxes.md BR-32).
 *
 * The alphabet omits characters that are ambiguous when spoken or rendered
 * small: no 0 or O, no 1, l or I. Strength comes from length rather than from
 * punctuation the recipient will mistype on a phone keyboard (BR-33).
 */
const ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789'

const BLOCKS = 4
const BLOCK_LENGTH = 4

export function suggestPassword(): string {
    const bytes = new Uint32Array(BLOCKS * BLOCK_LENGTH)

    // The platform CSPRNG. Math.random is not a password source.
    crypto.getRandomValues(bytes)

    const characters = Array.from(bytes, (byte) => ALPHABET[byte % ALPHABET.length])

    const blocks: string[] = []

    for (let index = 0; index < BLOCKS; index++) {
        blocks.push(characters.slice(index * BLOCK_LENGTH, (index + 1) * BLOCK_LENGTH).join(''))
    }

    // 16 characters of entropy, hyphenated into blocks that survive being read
    // aloud. Comfortably above the twelve the server enforces.
    return blocks.join('-')
}
