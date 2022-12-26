module.exports = function (grunt) {
  // Project configuration.
  grunt.initConfig({
      files: {
        src:  [
          '**/*.php',         // Include all files
          'includes/*.php', // Include includes
          '!node_modules/**', // Exclude node_modules/
          '!tests/**',        // Exclude tests/
          '!vendor/**',       // Exclude vendor/
	  '!static/**',   // Exclude static resources
        ],
        expand: true
     }
   },

    wp_readme_to_markdown: {
      target: {
        files: {
          'readme.md': 'readme.txt'
        }
      }
    },
    makepot: {
      target: {
        options: {
          mainFile: 'indieauth.php',
          domainPath: '/languages',
          exclude: ['build/.*'],
          potFilename: 'indieauth.pot',
          type: 'wp-plugin',
          updateTimestamp: true
        }
      }
    }
  });

  grunt.loadNpmTasks('grunt-wp-readme-to-markdown');
  grunt.loadNpmTasks('grunt-wp-i18n');
  // Default task(s).
  grunt.registerTask('default', ['wp_readme_to_markdown', 'makepot']);
};
