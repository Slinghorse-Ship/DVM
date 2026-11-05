package de.deinvereinsmanager.app;

import java.net.IDN;
import java.net.URI;
import java.util.Locale;

/** One selected website; credentials never cross its exact scheme/host/port boundary. */
public final class OriginPolicy {
    private final URI origin;

    public OriginPolicy(String entered, boolean debug) {
        try {
            URI value = new URI(entered.trim());
            String scheme = value.getScheme();
            String authority = value.getRawAuthority();
            if (scheme == null || authority == null || value.getRawUserInfo() != null
                    || value.getRawQuery() != null || value.getRawFragment() != null
                    || !(value.getRawPath().isEmpty() || value.getRawPath().equals("/"))
                    || authority.contains("%") || authority.contains("@") || authority.contains("\\")) throw new IllegalArgumentException();
            scheme = scheme.toLowerCase(Locale.ROOT);
            String host = value.getHost();
            int port = value.getPort();
            if (host == null) {
                if (authority.contains(":")) throw new IllegalArgumentException();
                host = IDN.toASCII(authority, IDN.USE_STD3_ASCII_RULES);
            }
            host = host.toLowerCase(Locale.ROOT);
            if (host.isEmpty() || host.endsWith(".")) throw new IllegalArgumentException();
            boolean local = host.equals("localhost") || host.equals("127.0.0.1") || host.equals("10.0.2.2") || host.equals("[::1]");
            if (!scheme.equals("https") && !(debug && scheme.equals("http") && local)) throw new IllegalArgumentException();
            if (port == 0 || port > 65535 || port < -1) throw new IllegalArgumentException();
            if (port == (scheme.equals("https") ? 443 : 80)) port = -1;
            origin = new URI(scheme, null, host, port, null, null, null);
        } catch (Exception invalid) {
            throw new IllegalArgumentException("Bitte die HTTPS-Adresse deines Vereins ohne Pfad, Anmeldung oder Zusatz eingeben.");
        }
    }

    public String origin() { return origin.toASCIIString(); }
    public String start() { return origin() + "/vereinsverwaltung/?view=dashboard"; }

    public boolean owns(String target) {
        try {
            URI url = new URI(target);
            if (url.getRawUserInfo() != null || url.getHost() == null || !origin.getScheme().equalsIgnoreCase(url.getScheme())) return false;
            int port = url.getPort();
            if (port == (origin.getScheme().equals("https") ? 443 : 80)) port = -1;
            return origin.getHost().equalsIgnoreCase(url.getHost()) && port == origin.getPort();
        } catch (Exception invalid) { return false; }
    }

    public static boolean external(String target) {
        try {
            URI url = new URI(target);
            String scheme = url.getScheme();
            if (scheme == null || target.indexOf('\n') >= 0 || target.indexOf('\r') >= 0) return false;
            return (scheme.equalsIgnoreCase("https") && url.getHost() != null && url.getRawUserInfo() == null)
                    || scheme.equalsIgnoreCase("mailto") || scheme.equalsIgnoreCase("tel");
        } catch (Exception invalid) { return false; }
    }
}
