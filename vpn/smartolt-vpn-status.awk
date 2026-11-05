# OpenVPN status-version 2, with field positions taken from its HEADER record.
# v2 gained Virtual IPv6 Address and other fields across releases; fixed offsets break.
BEGIN { FS = ","; header = 0; complete = 0; result = "offline" }
$1 == "HEADER" && $2 == "CLIENT_LIST" {
    for (i = 3; i <= NF; i++) fields[$i] = i - 1
    header = fields["Common Name"] && fields["Real Address"] && fields["Connected Since"]
}
$1 == "CLIENT_LIST" && header && $(fields["Common Name"]) == user {
    address = $(fields["Real Address"])
    sub(/^\[AF_INET6?\]/, "", address)
    sub(/:[0-9]+$/, "", address)
    sub(/^\[/, "", address)
    sub(/\]$/, "", address)
    result = "connected " address " " $(fields["Connected Since"])
}
$1 == "END" { complete = 1 }
END {
    if (!header || !complete) {
        print "VPN status is incomplete or has an unsupported format" > "/dev/stderr"
        exit 5
    }
    print result
}
