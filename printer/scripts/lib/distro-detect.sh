# shellcheck shell=bash
# Distro detection helpers — sourced by other scripts.
#
# Exports after `detect_distro`:
#   DISTRO_ID      e.g. "debian", "ubuntu", "arch", "fedora", "rhel"
#   DISTRO_FAMILY  one of: debian | arch | rhel | unknown
#   PKG_MANAGER    e.g. "apt", "pacman", "dnf"
#   INSTALL_CMD    e.g. "apt install -y", "pacman -S --noconfirm", "dnf install -y"
#   PKG_DRIVER_DEPS  space-separated package list to install for building the CUPS filter

detect_distro() {
    DISTRO_ID=""; DISTRO_FAMILY="unknown"; PKG_MANAGER=""; INSTALL_CMD=""; PKG_DRIVER_DEPS=""

    if [ -r /etc/os-release ]; then
        # shellcheck disable=SC1091
        . /etc/os-release
        DISTRO_ID="${ID:-unknown}"
        ID_LIKE_VAR="${ID_LIKE:-}"
    else
        DISTRO_ID="unknown"
        ID_LIKE_VAR=""
    fi

    case "$DISTRO_ID:$ID_LIKE_VAR" in
        debian:*|ubuntu:*|*"debian"*|linuxmint:*)
            DISTRO_FAMILY=debian
            PKG_MANAGER=apt
            INSTALL_CMD="apt-get install -y --no-install-recommends"
            PKG_DRIVER_DEPS="build-essential cmake libcups2-dev libcupsimage2-dev cups"
            ;;
        arch:*|manjaro:*|endeavouros:*|*"arch"*)
            DISTRO_FAMILY=arch
            PKG_MANAGER=pacman
            INSTALL_CMD="pacman -S --needed --noconfirm"
            PKG_DRIVER_DEPS="base-devel cmake cups"
            ;;
        fedora:*|rhel:*|rocky:*|almalinux:*|*"rhel"*|*"fedora"*)
            DISTRO_FAMILY=rhel
            PKG_MANAGER=dnf
            INSTALL_CMD="dnf install -y"
            PKG_DRIVER_DEPS="gcc-c++ make cmake cups-devel cups"
            ;;
        *)
            DISTRO_FAMILY=unknown
            ;;
    esac

    export DISTRO_ID DISTRO_FAMILY PKG_MANAGER INSTALL_CMD PKG_DRIVER_DEPS
}

# Echo a one-liner the user can copy-paste to install build deps if auto-install fails.
manual_install_hint() {
    case "$DISTRO_FAMILY" in
        debian) echo "sudo apt install -y $PKG_DRIVER_DEPS" ;;
        arch)   echo "sudo pacman -S --needed $PKG_DRIVER_DEPS" ;;
        rhel)   echo "sudo dnf install -y $PKG_DRIVER_DEPS" ;;
        *)      echo "(unknown distro — install gcc, make, cmake, cups, and cups dev headers manually)" ;;
    esac
}
