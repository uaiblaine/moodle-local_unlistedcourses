# Count any category with a state row as unlisted: a public category then holds back what is inside it.
s/\{\$unlisted\}\.state = /{\$unlisted}.state >= /;
