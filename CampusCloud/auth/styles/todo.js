// Simple To-Do List (localStorage)
const input = document.getElementById('todo-input');
const addBtn = document.getElementById('add-todo');
const list = document.getElementById('todo-items');

let todos = JSON.parse(localStorage.getItem('adminTodos')) || [];

function saveTodos() {
  localStorage.setItem('adminTodos', JSON.stringify(todos));
}

function renderTodos() {
  list.innerHTML = '';
  todos.forEach((todo, index) => {
    const li = document.createElement('li');
    li.className = todo.completed ? 'completed' : '';
    li.innerHTML = `
      <span>${todo.text}</span>
      <div>
        <button class="toggle-btn">✓</button>
        <button class="delete-btn">🗑</button>
      </div>
    `;

    li.querySelector('.toggle-btn').onclick = () => {
      todos[index].completed = !todos[index].completed;
      saveTodos();
      renderTodos();
    };

    li.querySelector('.delete-btn').onclick = () => {
      todos.splice(index, 1);
      saveTodos();
      renderTodos();
    };

    list.appendChild(li);
  });
}

addBtn.addEventListener('click', (e) => {
  if (e.target.key = "ENTER") {
    const value = input.value.trim();
    if (value) {
      todos.push({ text: value, completed: false });
      input.value = '';
      saveTodos();
      renderTodos();
    }
  }
})
addBtn.onclick = () => {
  const value = input.value.trim();
  if (value) {
    todos.push({ text: value, completed: false });
    input.value = '';
    saveTodos();
    renderTodos();
  }
}

renderTodos();
