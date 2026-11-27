<?php
/**
 * DNS lookups and SPF / DMARC / DKIM analysis.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Support;

/**
 * Thin wrapper over dns_get_record() (filterable for tests and for hosts that
 * disable it) plus pure parsing helpers.
 */
final class Dns {

	/**
	 * TXT records for a name, or null when DNS lookups are unavailable.
	 *
	 * @param string $name Host name.
	 * @return array<int,string>|null
	 */
	public static function txt( $name ) {
		/**
		 * Short-circuit TXT lookups. Return an array of strings to skip DNS.
		 *
		 * @param array<int,string>|null $records Records or null.
		 * @param string                 $name    Host name.
		 */
		$pre = apply_filters( 'iwiesm_pre_dns_txt', null, $name );
		if ( is_array( $pre ) ) {
			return array_values( array_map( 'strval', $pre ) );
		}
		if ( ! function_exists( 'dns_get_record' ) ) {
			return null;
		}
		$records = @dns_get_record( $name, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- returns false with a warning on NXDOMAIN.
		if ( ! is_array( $records ) ) {
			return array();
		}
		$out = array();
		foreach ( $records as $record ) {
			if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
				$out[] = implode( '', $record['entries'] );
			} elseif ( isset( $record['txt'] ) ) {
				$out[] = (string) $record['txt'];
			}
		}
		return $out;
	}

	/**
	 * CNAME target for a name ('' when none, null when DNS unavailable).
	 *
	 * @param string $name Host name.
	 * @return string|null
	 */
	public static function cname( $name ) {
		$pre = apply_filters( 'iwiesm_pre_dns_cname', null, $name );
		if ( is_string( $pre ) ) {
			return $pre;
		}
		if ( ! function_exists( 'dns_get_record' ) ) {
			return null;
		}
		$records = @dns_get_record( $name, DNS_CNAME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see txt().
		if ( is_array( $records ) && ! empty( $records[0]['target'] ) ) {
			return strtolower( (string) $records[0]['target'] );
		}
		return '';
	}

	/**
	 * Domain part of an email address.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function domain_of( $email ) {
		$at = strrpos( (string) $email, '@' );
		return false === $at ? '' : strtolower( substr( (string) $email, $at + 1 ) );
	}

	/**
	 * SPF records (v=spf1) among TXT records.
	 *
	 * @param array<int,string> $txt TXT records.
	 * @return array<int,string>
	 */
	public static function spf_records( array $txt ) {
		$out = array();
		foreach ( $txt as $record ) {
			$record = trim( $record, " \t\"" );
			if ( preg_match( '/^v=spf1(\s|$)/i', $record ) ) {
				$out[] = $record;
			}
		}
		return $out;
	}

	/**
	 * Whether an SPF record authorises an include domain (or one of them).
	 *
	 * @param string            $spf      SPF record.
	 * @param array<int,string> $includes Include domains.
	 * @return bool
	 */
	public static function spf_includes( $spf, array $includes ) {
		$spf = strtolower( $spf );
		foreach ( $includes as $include ) {
			if ( preg_match( '/(^|\s)[+]?(include|redirect)[:=]' . preg_quote( strtolower( $include ), '/' ) . '(\s|$)/', $spf ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The "all" qualifier of an SPF record: + ? ~ - or '' when absent.
	 *
	 * @param string $spf SPF record.
	 * @return string
	 */
	public static function spf_all( $spf ) {
		if ( preg_match( '/(^|\s)([+?~-]?)all(\s|$)/i', $spf, $m ) ) {
			return '' === $m[2] ? '+' : $m[2];
		}
		return '';
	}

	/**
	 * DMARC records among TXT records.
	 *
	 * @param array<int,string> $txt TXT records.
	 * @return array<int,string>
	 */
	public static function dmarc_records( array $txt ) {
		$out = array();
		foreach ( $txt as $record ) {
			$record = trim( $record, " \t\"" );
			if ( preg_match( '/^v=DMARC1(\s*;|$)/i', $record ) ) {
				$out[] = $record;
			}
		}
		return $out;
	}

	/**
	 * DMARC tags as an array (lowercase keys).
	 *
	 * @param string $record DMARC record.
	 * @return array<string,string>
	 */
	public static function dmarc_tags( $record ) {
		$tags = array();
		foreach ( explode( ';', $record ) as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( 2 === count( $pair ) ) {
				$tags[ strtolower( trim( $pair[0] ) ) ] = strtolower( trim( $pair[1] ) );
			}
		}
		return $tags;
	}

	/**
	 * Candidate domains for a DMARC lookup: the domain, then parents down to
	 * two labels (a simple stand-in for the organisational domain).
	 *
	 * @param string $domain Domain.
	 * @return array<int,string>
	 */
	public static function dmarc_candidates( $domain ) {
		$labels = explode( '.', strtolower( trim( $domain, '.' ) ) );
		$out    = array();
		$count  = count( $labels );
		while ( $count >= 2 ) {
			$out[] = implode( '.', $labels );
			array_shift( $labels );
			--$count;
		}
		return $out;
	}

	/**
	 * Whether a TXT record looks like a DKIM public key.
	 *
	 * @param array<int,string> $txt TXT records.
	 * @return bool
	 */
	public static function has_dkim_key( array $txt ) {
		foreach ( $txt as $record ) {
			if ( preg_match( '/(^|;)\s*p=[A-Za-z0-9+\/=]{20,}/', $record ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Known sending providers for an SMTP host: SPF include domains and
	 * common DKIM selectors.
	 *
	 * @param string $mailer Mailer id.
	 * @param string $host   SMTP host.
	 * @return array{name:string,spf:array<int,string>,dkim:array<int,string>}|null
	 */
	public static function provider( $mailer, $host = '' ) {
		if ( 'microsoft' === $mailer ) {
			return array(
				'name' => 'Microsoft 365',
				'spf'  => array( 'spf.protection.outlook.com' ),
				'dkim' => array( 'selector1', 'selector2' ),
			);
		}
		if ( 'google' === $mailer ) {
			return array(
				'name' => 'Google',
				'spf'  => array( '_spf.google.com' ),
				'dkim' => array( 'google' ),
			);
		}
		if ( 'smtp' !== $mailer || '' === $host ) {
			return null;
		}
		$host  = strtolower( $host );
		$known = array(
			'office365.com'     => array( 'Microsoft 365', array( 'spf.protection.outlook.com' ), array( 'selector1', 'selector2' ) ),
			'outlook.com'       => array( 'Microsoft 365', array( 'spf.protection.outlook.com' ), array( 'selector1', 'selector2' ) ),
			'gmail.com'         => array( 'Google', array( '_spf.google.com' ), array( 'google' ) ),
			'googlemail.com'    => array( 'Google', array( '_spf.google.com' ), array( 'google' ) ),
			'sendgrid.net'      => array( 'SendGrid', array( 'sendgrid.net' ), array( 's1', 's2' ) ),
			'mailgun.org'       => array( 'Mailgun', array( 'mailgun.org', 'eu.mailgun.org' ), array( 'mx', 'k1', 'smtp', 'pic' ) ),
			'amazonaws.com'     => array( 'Amazon SES', array( 'amazonses.com' ), array() ),
			'brevo.com'         => array( 'Brevo', array( 'spf.brevo.com', 'spf.sendinblue.com' ), array( 'brevo1', 'brevo2', 'mail' ) ),
			'sendinblue.com'    => array( 'Brevo', array( 'spf.brevo.com', 'spf.sendinblue.com' ), array( 'brevo1', 'brevo2', 'mail' ) ),
			'postmarkapp.com'   => array( 'Postmark', array( 'spf.mtasv.net' ), array() ),
			'zoho.com'          => array( 'Zoho Mail', array( 'zoho.com', 'zohomail.com' ), array( 'zmail' ) ),
			'zoho.eu'           => array( 'Zoho Mail', array( 'zoho.eu', 'zohomail.eu' ), array( 'zmail' ) ),
			'smtp2go.com'       => array( 'SMTP2GO', array( 'spf.smtp2go.com' ), array( 's1' ) ),
			'mailjet.com'       => array( 'Mailjet', array( 'spf.mailjet.com' ), array( 'mailjet' ) ),
			'sparkpostmail.com' => array( 'SparkPost', array( '_spf.sparkpostmail.com' ), array() ),
			'mandrillapp.com'   => array( 'Mailchimp Transactional', array( 'spf.mandrillapp.com' ), array( 'mte1', 'mte2' ) ),
			'protonmail.ch'     => array( 'Proton Mail', array( '_spf.protonmail.ch' ), array( 'protonmail', 'protonmail2', 'protonmail3' ) ),
			'mail.me.com'       => array( 'iCloud Mail', array( 'icloud.com' ), array( 'sig1' ) ),
		);
		foreach ( $known as $suffix => $info ) {
			if ( $host === $suffix || substr( $host, -strlen( '.' . $suffix ) ) === '.' . $suffix ) {
				return array(
					'name' => $info[0],
					'spf'  => $info[1],
					'dkim' => $info[2],
				);
			}
		}
		return null;
	}
}
