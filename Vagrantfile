# -*- mode: ruby -*-
# # vi: set ft=ruby :

########## Begin configuration switches ##########

# Use PHP 5.2 (vs default of 5.5)
PHP52 = true

# Install zip ext for 5.2
PHP52_ZIP = true

# Wordpress version
WORDPRESS = '3.0'

# Do multisite install
MULTISITE = false

# Use ftp file access method (vs default of direct)
FS_METHOD_FTP = true

# If above is true, set up chrooted ftp
FTP_CHROOT = true

########## End configuration switches ##########

Vagrant.configure('2') do |config|
  config.vm.box = PHP52 ? 'ubuntu/precise32' : 'ubuntu/trusty32'

  config.vm.hostname = "cambrian"
  config.vm.network "private_network", ip: "99.99.99.10"

  config.vm.synced_folder ".", "/vagrant", :nfs => true

  # Provision wordpress test machine
  config.vm.provision :ansible do |ansible|
    ansible.playbook = "provision/provision.yml"
    ansible.extra_vars = {
      php52:             PHP52,
      php52_zip:         PHP52_ZIP,
      wordpress_version: WORDPRESS,
      is_multisite:      MULTISITE,
      wp_fsmethod_ftp:   FS_METHOD_FTP,
      wp_ftp_chroot:     FTP_CHROOT,
    }
  end
end
