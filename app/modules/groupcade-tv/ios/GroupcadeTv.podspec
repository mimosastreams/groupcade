Pod::Spec.new do |s|
  s.name           = 'GroupcadeTv'
  s.version        = '1.0.0'
  s.summary        = 'AirPlay second screen for Groupcade'
  s.description    = 'Shows the room TV page on an external display (AirPlay or a cable) while the phone stays the controller.'
  s.license        = 'UNLICENSED'
  s.author         = 'Groupcade'
  s.homepage       = 'https://groupcade.com'
  s.platforms      = { :ios => '16.4' }
  s.swift_version  = '5.9'
  s.source         = { git: '' }
  s.static_framework = true

  s.dependency 'ExpoModulesCore'

  s.source_files = '**/*.{h,m,swift}'
  s.frameworks = 'UIKit', 'WebKit'
  s.pod_target_xcconfig = {
    'DEFINES_MODULE' => 'YES',
    'SWIFT_COMPILATION_MODE' => 'wholemodule'
  }
end
