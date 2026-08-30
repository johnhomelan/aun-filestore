// Native implementations of the ext-ldap functions LdapClient.php uses, backed
// by the OpenLDAP client library (libldap / liblber), for TypePHP builds. The
// libphp that tpc links has no `ldap` extension, so without this shim the
// AuthPluginLdap / LdapClient auth backend transpiles but every ldap_* call
// falls through to an undefined function at run time.
//
// Exposed to PHP by the matching declarations in ldap_openldap.stub.php
// (php_<name> in C++  <->  <name>() in PHP) plus the compile-only class and
// constant files ldap_classes.php / ldap_constants.php. This whole set is
// referenced only from packaging/typephp/project*.yml - never autoloaded - so
// under a normal (interpreted) run the real ext-ldap is used and nothing here
// is in scope.
//
// Connections and search results are kept in process-global handle tables; the
// PHP side gets back an \LDAP\Connection / \LDAP\Result object carrying only an
// integer id (see ldap_classes.php). This mirrors how ext-ldap hands out opaque
// objects and keeps LdapClient.php's `?\LDAP\Connection` / `instanceof
// \LDAP\Result` code compiling and working unchanged.

#include <phpx.h>
#include <phpx_func.h>

#include <ldap.h>
#include <lber.h>

#include <sys/time.h>
#include <cctype>
#include <cstdlib>
#include <cstring>
#include <map>
#include <string>
#include <vector>

using namespace php;

namespace {

std::map<zend_long, LDAP *>        g_conns;
std::map<zend_long, LDAPMessage *> g_results;
zend_long                         g_next_handle = 0;

// --- handle <-> object plumbing --------------------------------------------

Object make_handle(const char *class_name, zend_long id)
{
    Object o = newObject(String(class_name));
    o.set(String("id"), Variant(id));
    return o;
}

zend_long handle_id(const Variant &v)
{
    if (!v.isObject()) {
        return 0;
    }
    Object o(v);
    return o.get(String("id")).toInt();
}

LDAP *conn_from(const Variant &v)
{
    const zend_long id = handle_id(v);
    auto it = g_conns.find(id);
    return it == g_conns.end() ? nullptr : it->second;
}

LDAPMessage *result_from(const Variant &v)
{
    const zend_long id = handle_id(v);
    auto it = g_results.find(id);
    return it == g_results.end() ? nullptr : it->second;
}

// --- LDAPMod marshalling for add / modify ---------------------------------
//
// $entry is array<string, string|array<string>>. An empty value array means
// "the whole attribute" (used by ldap_mod_del to strip an attribute).

char *dup_cstr(const char *src, size_t len)
{
    char *out = static_cast<char *>(malloc(len + 1));
    if (out != nullptr) {
        memcpy(out, src, len);
        out[len] = '\0';
    }
    return out;
}

LDAPMod **build_mods(const Array &entry, int mod_op)
{
    const size_t count = entry.count();
    LDAPMod **mods = static_cast<LDAPMod **>(calloc(count + 1, sizeof(LDAPMod *)));
    size_t mi = 0;

    for (auto kv : entry) {
        LDAPMod *mod = static_cast<LDAPMod *>(calloc(1, sizeof(LDAPMod)));
        mod->mod_op = mod_op;

        String key(kv.key);
        mod->mod_type = dup_cstr(key.data(), key.length());

        std::vector<char *> values;
        Variant val = kv.value;
        if (val.isArray()) {
            Array list(val);
            for (auto vv : list) {
                String s(vv.value);
                values.push_back(dup_cstr(s.data(), s.length()));
            }
        } else if (!val.isNull()) {
            String s(val);
            values.push_back(dup_cstr(s.data(), s.length()));
        }

        if (values.empty()) {
            mod->mod_values = nullptr;
        } else {
            char **cv = static_cast<char **>(calloc(values.size() + 1, sizeof(char *)));
            for (size_t i = 0; i < values.size(); i++) {
                cv[i] = values[i];
            }
            cv[values.size()] = nullptr;
            mod->mod_values = cv;
        }

        mods[mi++] = mod;
    }
    mods[mi] = nullptr;
    return mods;
}

void free_mods(LDAPMod **mods)
{
    for (size_t i = 0; mods[i] != nullptr; i++) {
        free(mods[i]->mod_type);
        if (mods[i]->mod_values != nullptr) {
            for (size_t j = 0; mods[i]->mod_values[j] != nullptr; j++) {
                free(mods[i]->mod_values[j]);
            }
            free(mods[i]->mod_values);
        }
        free(mods[i]);
    }
    free(mods);
}

bool do_modify(const Variant &ldap, const String &dn, const Array &entry, int mod_op, bool is_add)
{
    LDAP *ld = conn_from(ldap);
    if (ld == nullptr) {
        return false;
    }
    LDAPMod **mods = build_mods(entry, mod_op);
    const int rc = is_add
        ? ldap_add_ext_s(ld, dn.data(), mods, nullptr, nullptr)
        : ldap_modify_ext_s(ld, dn.data(), mods, nullptr, nullptr);
    free_mods(mods);
    return rc == LDAP_SUCCESS;
}

} // namespace

// --- connect / options / bind -------------------------------------------------

Variant php_ldap_connect(String uri)
{
    LDAP *ld = nullptr;
    const int rc = ldap_initialize(&ld, uri.length() > 0 ? uri.data() : nullptr);
    if (rc != LDAP_SUCCESS || ld == nullptr) {
        return Variant(false);
    }
    const zend_long id = ++g_next_handle;
    g_conns[id] = ld;
    return make_handle("LDAP\\Connection", id);
}

Bool php_ldap_set_option(Variant ldap, Int option, Variant value)
{
    LDAP *ld = conn_from(ldap);
    if (ld == nullptr) {
        return false;
    }

    int rc;
    if (option == LDAP_OPT_NETWORK_TIMEOUT || option == LDAP_OPT_TIMEOUT) {
        struct timeval tv;
        tv.tv_sec  = static_cast<long>(value.toInt());
        tv.tv_usec = 0;
        rc = ldap_set_option(ld, static_cast<int>(option), &tv);
    } else if (value.isString()) {
        rc = ldap_set_option(ld, static_cast<int>(option), value.toCString());
    } else {
        int iv = static_cast<int>(value.toInt());
        rc = ldap_set_option(ld, static_cast<int>(option), &iv);
    }
    return rc == LDAP_OPT_SUCCESS;
}

Bool php_ldap_start_tls(Variant ldap)
{
    LDAP *ld = conn_from(ldap);
    if (ld == nullptr) {
        return false;
    }
    return ldap_start_tls_s(ld, nullptr, nullptr) == LDAP_SUCCESS;
}

Bool php_ldap_bind(Variant ldap, Variant dn, Variant password)
{
    LDAP *ld = conn_from(ldap);
    if (ld == nullptr) {
        return false;
    }

    const char *bind_dn = dn.isNull() ? nullptr : dn.toCString();

    std::string pw;
    if (!password.isNull()) {
        String s(password);
        pw.assign(s.data(), s.length());
    }
    struct berval cred;
    cred.bv_val = password.isNull() ? nullptr : const_cast<char *>(pw.c_str());
    cred.bv_len = password.isNull() ? 0 : pw.size();

    const int rc = ldap_sasl_bind_s(ld, bind_dn, LDAP_SASL_SIMPLE, &cred,
                                    nullptr, nullptr, nullptr);
    return rc == LDAP_SUCCESS;
}

Bool php_ldap_unbind(Variant ldap)
{
    const zend_long id = handle_id(ldap);
    auto it = g_conns.find(id);
    if (it == g_conns.end()) {
        return false;
    }
    ldap_unbind_ext_s(it->second, nullptr, nullptr);
    g_conns.erase(it);
    return true;
}

// --- search / entry extraction ----------------------------------------------

Variant php_ldap_search(Variant ldap, String base_dn, String filter)
{
    LDAP *ld = conn_from(ldap);
    if (ld == nullptr) {
        return Variant(false);
    }

    LDAPMessage *res = nullptr;
    const int rc = ldap_search_ext_s(
        ld,
        base_dn.length() > 0 ? base_dn.data() : "",
        LDAP_SCOPE_SUBTREE,
        filter.length() > 0 ? filter.data() : "(objectClass=*)",
        nullptr, 0, nullptr, nullptr, nullptr, LDAP_NO_LIMIT, &res);

    if (rc != LDAP_SUCCESS) {
        if (res != nullptr) {
            ldap_msgfree(res);
        }
        return Variant(false);
    }

    const zend_long id = ++g_next_handle;
    g_results[id] = res;
    return make_handle("LDAP\\Result", id);
}

// Builds ext-ldap's ldap_get_entries() shape:
//   ['count'=>N, 0=>['count'=>M, 0=>'attr', 'attr'=>['count'=>K,0=>v,...],
//                    'dn'=>'...'], 1=>[...], ...]
// Attribute names are lower-cased, as ext-ldap does. LdapClient::search() reads
// each result exactly once, so the underlying LDAPMessage is freed here.
Variant php_ldap_get_entries(Variant ldap, Variant result)
{
    LDAP *ld = conn_from(ldap);
    LDAPMessage *res = result_from(result);
    if (ld == nullptr || res == nullptr) {
        return Variant(false);
    }

    Array top;
    zend_long entry_index = 0;

    for (LDAPMessage *e = ldap_first_entry(ld, res); e != nullptr; e = ldap_next_entry(ld, e)) {
        Array entry;
        zend_long attr_index = 0;

        char *dn = ldap_get_dn(ld, e);
        entry.set("dn", Variant(dn != nullptr ? dn : ""));
        if (dn != nullptr) {
            ldap_memfree(dn);
        }

        BerElement *ber = nullptr;
        for (char *attr = ldap_first_attribute(ld, e, &ber);
             attr != nullptr;
             attr = ldap_next_attribute(ld, e, ber)) {

            std::string lname(attr);
            for (char &c : lname) {
                c = static_cast<char>(std::tolower(static_cast<unsigned char>(c)));
            }

            Array values;
            zend_long value_count = 0;
            struct berval **bvals = ldap_get_values_len(ld, e, attr);
            if (bvals != nullptr) {
                for (int i = 0; bvals[i] != nullptr; i++) {
                    values.set(static_cast<zend_ulong>(i),
                               Variant(bvals[i]->bv_val, bvals[i]->bv_len));
                    value_count++;
                }
                ldap_value_free_len(bvals);
            }
            values.set("count", Variant(value_count));

            entry.set(lname.c_str(), values);
            entry.set(static_cast<zend_ulong>(attr_index), Variant(lname.c_str()));
            attr_index++;
            ldap_memfree(attr);
        }
        if (ber != nullptr) {
            ber_free(ber, 0);
        }

        entry.set("count", Variant(attr_index));
        top.set(static_cast<zend_ulong>(entry_index), entry);
        entry_index++;
    }
    top.set("count", Variant(entry_index));

    ldap_msgfree(res);
    g_results.erase(handle_id(result));
    return top;
}

// --- add / modify / delete -------------------------------------------------

Bool php_ldap_add(Variant ldap, String dn, Array entry)
{
    return do_modify(ldap, dn, entry, LDAP_MOD_ADD, /*is_add=*/true);
}

Bool php_ldap_mod_replace(Variant ldap, String dn, Array entry)
{
    return do_modify(ldap, dn, entry, LDAP_MOD_REPLACE, /*is_add=*/false);
}

Bool php_ldap_mod_add(Variant ldap, String dn, Array entry)
{
    return do_modify(ldap, dn, entry, LDAP_MOD_ADD, /*is_add=*/false);
}

Bool php_ldap_mod_del(Variant ldap, String dn, Array entry)
{
    return do_modify(ldap, dn, entry, LDAP_MOD_DELETE, /*is_add=*/false);
}

Bool php_ldap_delete(Variant ldap, String dn)
{
    LDAP *ld = conn_from(ldap);
    if (ld == nullptr) {
        return false;
    }
    return ldap_delete_ext_s(ld, dn.data(), nullptr, nullptr) == LDAP_SUCCESS;
}

// --- ldap_escape (pure, no libldap needed) --------------------------------
//
// flags: bit 0 = LDAP_ESCAPE_FILTER, bit 1 = LDAP_ESCAPE_DN. 0 means both, as
// in PHP. Characters present in $ignore are passed through untouched.

String php_ldap_escape(String value, String ignore, Int flags)
{
    static const char *hex = "0123456789abcdef";

    const bool esc_filter = (flags & 1) != 0 || flags == 0;
    const bool esc_dn     = (flags & 2) != 0 || flags == 0;

    const std::string ign(ignore.data(), ignore.length());
    const char *p = value.data();
    const size_t n = value.length();

    std::string out;
    out.reserve(n);
    for (size_t i = 0; i < n; i++) {
        const unsigned char c = static_cast<unsigned char>(p[i]);
        bool escape = false;

        if (c == 0) {
            escape = true;
        }
        if (esc_filter && (c == '*' || c == '(' || c == ')' || c == '\\')) {
            escape = true;
        }
        if (esc_dn && (c == '\\' || c == ',' || c == '+' || c == '"' ||
                       c == '<' || c == '>' || c == ';')) {
            escape = true;
        }
        if (esc_dn && i == 0 && (c == ' ' || c == '#')) {
            escape = true;
        }
        if (esc_dn && i == n - 1 && c == ' ') {
            escape = true;
        }
        if (c != 0 && ign.find(static_cast<char>(c)) != std::string::npos) {
            escape = false;
        }

        if (escape) {
            out.push_back('\\');
            out.push_back(hex[c >> 4]);
            out.push_back(hex[c & 0x0F]);
        } else {
            out.push_back(static_cast<char>(c));
        }
    }
    return String(out);
}
